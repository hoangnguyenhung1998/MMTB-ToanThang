<?php

namespace App\Services\Reconciliation;

use Illuminate\Support\Facades\DB;

/** Relationship-only plans: no materialization, pairing or OCR is invoked. */
class CanonicalAssignmentRelinker
{
    private array $cases = [];

    private array $byScope = [];

    private array $byDay = [];

    private array $members = [];

    private array $intervals = [];

    private array $caseIntervals = [];

    private array $jobs = [];

    private array $jobsById = [];

    private array $blocked = [];

    private array $blockedCases = [];

    private array $updates = [];

    private array $deletes = [];

    private array $jobUpdates = [];

    private array $logs = [];

    public function __construct(array $machineIds, string $from, string $to, private readonly bool $lock = true, array $referenceJobIds = [])
    {
        $cases = DB::table('daily_photo_cases')->whereIn('machine_id', $machineIds)
            ->whereBetween('work_date', [$from, $to.' 23:59:59'])->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        foreach ($cases as $case) {
            $case->work_date = substr($case->work_date, 0, 10);
            $this->cases[$case->id] = $case;
            $this->byDay[$case->machine_id.'|'.$case->work_date][$case->id] = $case;
            $this->byScope[$this->key($case->machine_id, $case->work_date, $case->machine_assignment_id)][] = $case->id;
        }
        if ($cases->isEmpty()) {
            if ($referenceJobIds) {
                $this->jobsById = DB::table('ocr_jobs')->whereIn('id', $referenceJobIds)
                    ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id')->all();
            }

            return;
        }
        $ids = $cases->pluck('id')->all();
        $this->members = DB::table('daily_photo_case_evidence')->whereIn('daily_photo_case_id', $ids)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->groupBy('daily_photo_case_id')->all();
        $this->intervals = DB::table('daily_photo_intervals')->whereIn('daily_photo_case_id', $ids)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id')->all();
        foreach ($this->intervals as $interval) {
            $this->caseIntervals[$interval->daily_photo_case_id][] = $interval;
        }
        $memberJobIds = collect($this->members)->flatten(1)->pluck('ocr_job_id')->all();
        $jobs = DB::table('ocr_jobs')->where(fn ($q) => $q->whereIn('daily_photo_case_id', $ids)->orWhereIn('id', $memberJobIds)->orWhereIn('id', $referenceJobIds))
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $this->jobs = $jobs->groupBy('daily_photo_case_id')->all();
        $this->jobsById = $jobs->keyBy('id')->all();
        // A shared canonical case cannot be moved underneath a locked historical period/row.
        $protected = DB::table('reconciliation_rows as r')->join('reconciliation_periods as p', 'p.id', '=', 'r.reconciliation_period_id')
            ->whereIn('r.machine_id', $machineIds)->whereBetween('r.work_date', [$from, $to.' 23:59:59'])
            ->where(fn ($q) => $q->whereNotIn('p.status', ['DRAFT', 'GENERATED', 'REVIEWING'])
                ->orWhere('r.status', '!=', 'DRAFT')->orWhereNotNull('r.reviewed_at')->orWhereNotNull('r.confirmed_at')->orWhereNotNull('r.manually_edited_at')->orWhereNotNull('r.reviewed_by')->orWhereNotNull('r.confirmed_by'))
            ->select(['r.machine_id', 'r.work_date', 'r.machine_assignment_id', 'r.daily_ocr_job_ids', 'r.daily_intervals'])->when($lock, fn ($q) => $q->lockForUpdate())->get();
        foreach ($protected as $row) {
            $this->blocked[$this->key($row->machine_id, substr($row->work_date, 0, 10), $row->machine_assignment_id)] = true;
            // A protected row can itself have a stale assignment while referencing this case.
            foreach (json_decode($row->daily_ocr_job_ids ?? '[]', true) ?? [] as $id) {
                if ($caseId = $this->jobsById[$id]->daily_photo_case_id ?? null) {
                    $this->blockedCases[$caseId] = true;
                }
            }
            foreach (json_decode($row->daily_intervals ?? '[]', true) ?? [] as $part) {
                if ($caseId = $this->intervals[$part['canonical_interval_id'] ?? 0]->daily_photo_case_id ?? null) {
                    $this->blockedCases[$caseId] = true;
                }
            }

        }
    }

    public function protectedEvidence(object $row): bool
    {
        $ids = json_decode($row->daily_ocr_job_ids ?? '[]', true) ?? [];
        foreach ($this->byScope[$this->key($row->machine_id, $row->work_date, $row->machine_assignment_id)] ?? [] as $caseId) {
            foreach ($this->jobs[$caseId] ?? [] as $job) {
                $ids[] = $job->id;
            }
        }
        foreach ($ids as $id) {
            $job = $this->jobsById[$id] ?? null;
            if ($job && ($job->reviewed_at !== null || ($job->machine_resolution_method ?? null) === 'HUMAN'
                || ($job->ocr_final_source ?? null) === 'MANUAL' || in_array($job->review_status, ['APPROVED', 'CORRECTED'], true))) {
                return true;
            }
        }

        return false;
    }

    public function hasContent(object $row): bool
    {
        foreach ($this->byScope[$this->key($row->machine_id, $row->work_date, $row->machine_assignment_id)] ?? [] as $id) {
            if (! $this->emptyCase($id)) {
                return true;
            }
        }

        return false;
    }

    public function reason(object $row, object $target): ?string
    {
        $sourceRow = $this->sourceRow($row, $target);
        $sourceKey = $this->key($row->machine_id, $row->work_date, $sourceRow->machine_assignment_id);
        $targetKey = $this->key($row->machine_id, $row->work_date, $target->id);
        if (($target->ownership_policy ?? null) === 'BUSINESS_DAY' && $target->id !== null
            && count($this->populatedDayCases($row)) > 1) {
            return 'CANONICAL_CONFLICT';
        }
        $sourceIds = $this->byScope[$sourceKey] ?? [];
        $targetIds = $this->byScope[$targetKey] ?? [];
        if (count($sourceIds) > 1 || count($targetIds) > 1) {
            return 'CANONICAL_CONFLICT';
        }
        $sourceId = $sourceIds[0] ?? null;
        $targetId = $targetIds[0] ?? null;
        if ($sourceId && $sourceId !== $targetId && (isset($this->blocked[$sourceKey]) || isset($this->blocked[$targetKey]) || isset($this->blockedCases[$sourceId]) || isset($this->blockedCases[$targetId]))) {
            return 'PROTECTED_CANONICAL_RELATIONSHIP';
        }
        if ($sourceId && $sourceId !== $targetId) {
            foreach ($this->jobs[$sourceId] ?? [] as $job) {
                if ($job->reviewed_at !== null || ($job->machine_resolution_method ?? null) === 'HUMAN' || ($job->ocr_final_source ?? null) === 'MANUAL' || in_array($job->review_status, ['APPROVED', 'CORRECTED'], true)) {
                    return 'PROTECTED_CANONICAL_RELATIONSHIP';
                }
            }
        }
        if ($target->id === null && $sourceId !== $targetId) {
            if ($sourceId && (isset($this->blocked[$sourceKey]) || isset($this->blocked[$targetKey]) || isset($this->blockedCases[$sourceId]) || isset($this->blockedCases[$targetId]))) {
                return 'PROTECTED_CANONICAL_RELATIONSHIP';
            }
            if ($targetId && ! $this->emptyCase($targetId)) {
                return 'CANONICAL_CONFLICT';
            }
        }
        if ($sourceId && ! $this->emptyCase($sourceId)) {
            if ($sourceId !== $targetId && (isset($this->blocked[$sourceKey]) || isset($this->blocked[$targetKey]) || isset($this->blockedCases[$sourceId]) || isset($this->blockedCases[$targetId]))) {
                return 'PROTECTED_CANONICAL_RELATIONSHIP';
            }
            if ($targetId && $targetId !== $sourceId && ! $this->emptyCase($targetId)) {
                return 'CANONICAL_CONFLICT';
            }
            foreach ($this->members[$sourceId] ?? [] as $member) {
                $job = $this->jobsById[$member->ocr_job_id] ?? null;
                if (! $job || (int) $job->daily_photo_case_id !== (int) $sourceId
                    || ($job->machine_id !== null && (int) $job->machine_id !== (int) $row->machine_id)) {
                    return 'CANONICAL_CONFLICT';
                }
                if ($member->capture_datetime === null) {
                    [$start, $end] = AssignmentInterval::segment($target, $row->work_date);
                    if ($start !== '00:00:00' || $end !== '23:59:59') {
                        return 'CANONICAL_TIME_AMBIGUITY';
                    }
                } elseif (! $this->containsStamp($target, $member->capture_datetime)) {
                    return 'CANONICAL_TIME_CONFLICT';
                }
            }
            foreach ($this->jobs[$sourceId] ?? [] as $job) {
                if ($job->machine_id !== null && (int) $job->machine_id !== (int) $row->machine_id) {
                    return 'CANONICAL_CONFLICT';
                }
            }
            foreach ($this->caseIntervals[$sourceId] ?? [] as $interval) {
                if (! $this->containsStamp($target, $interval->raw_start_at) || ! $this->containsStamp($target, $interval->raw_end_at, true)) {
                    return 'CANONICAL_TIME_CONFLICT';
                }
            }
        }
        foreach (json_decode($row->daily_ocr_job_ids ?? '[]', true) ?? [] as $id) {
            $job = $this->jobsById[$id] ?? null;
            if ($job && $job->daily_photo_case_id !== null
                && ! in_array((int) $job->daily_photo_case_id, array_map('intval', array_filter([$sourceId, $targetId])), true)) {
                return 'CANONICAL_OCR_CONFLICT';
            }
        }
        foreach (json_decode($row->daily_intervals ?? '[]', true) ?? [] as $part) {
            if ($id = $part['canonical_interval_id'] ?? null) {
                $interval = $this->intervals[$id] ?? null;
                if (! $interval || ! in_array((int) $interval->daily_photo_case_id, array_map('intval', array_filter([$sourceId, $targetId])), true)) {
                    return 'CANONICAL_CONFLICT';
                }
            }
        }

        return null;
    }

    /** Prove a stale all-day segment may be narrowed without moving any business time. */
    public function canNarrow(object $row, object $target): bool
    {
        return $this->narrowingReason($row, $target) === null;
    }

    /** Same proof as Repair; explain why evidence cannot establish a unique safe segment. */
    public function narrowingReason(object $row, object $target): ?string
    {
        $ids = $this->byScope[$this->key($row->machine_id, $row->work_date, $row->machine_assignment_id)] ?? [];
        if (count($ids) !== 1) {
            return 'NO_SINGLE_CANONICAL_CASE';
        }
        if (empty($this->caseIntervals[$ids[0]])) {
            return 'CANONICAL_INTERVALS_MISSING';
        }
        if (! empty(json_decode($row->journal_row_ids ?? '[]', true))) {
            return 'JOURNAL_TIME_NOT_PROVEN';
        }
        [$start, $end] = AssignmentInterval::segment($target, $row->work_date);
        $start = max($start, $row->segment_start ?: $start);
        $end = min($end, $row->segment_end ?: $end);
        if ($start >= $end) {
            return 'NO_SEGMENT_INTERSECTION';
        }
        $bounded = (object) ['id' => $target->id, 'time_in' => $row->work_date.' '.$start, 'time_out' => $row->work_date.' '.$end];
        if ($reason = $this->reason($row, $bounded)) {
            return $reason;
        }
        $knownJobs = array_map(fn ($job) => (int) $job->id, ($this->jobs[$ids[0]] ?? collect())->all());
        foreach (json_decode($row->daily_ocr_job_ids ?? '[]', true) ?? [] as $id) {
            if (! in_array((int) $id, $knownJobs, true)) {
                return 'OCR_REFERENCE_OUTSIDE_CANONICAL_CASE';
            }
        }
        foreach (json_decode($row->daily_intervals ?? '[]', true) ?? [] as $part) {
            foreach (['start', 'end'] as $endpoint) {
                if (! empty($part[$endpoint])) {
                    $time = (string) $part[$endpoint];
                    $time = strlen($time) === 5 ? $time.':00' : $time;
                    if (! preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) || $time < $start || $time > $end
                        || (! empty($part[$endpoint.'_date']) && $part[$endpoint.'_date'] !== $row->work_date)) {
                        return 'DAILY_INTERVAL_OUTSIDE_CANDIDATE_SEGMENT';
                    }
                }
                if (! empty($part[$endpoint.'_job_id']) && ! in_array((int) $part[$endpoint.'_job_id'], $knownJobs, true)) {
                    return 'DAILY_INTERVAL_OCR_REFERENCE_CONFLICT';
                }
            }
        }
        $fields = ['ocr_check_in_raw', 'ocr_check_out_raw', 'rounded_check_in', 'rounded_check_out',
            'confirmed_check_in', 'confirmed_check_out', 'gps_check_in', 'gps_check_out'];
        foreach (DailyTimeAllocator::KINDS as $kind) {
            $fields[] = $kind.'_start';
            $fields[] = $kind.'_end';
            if (! empty($row->{$kind.'_start'}) && ! empty($row->{$kind.'_end'})
                && $row->{$kind.'_end'} <= $row->{$kind.'_start'}) {
                return 'OVERNIGHT_OR_NON_POSITIVE_ALLOCATION:'.$kind;
            }
        }
        foreach ($fields as $field) {
            if (! empty($row->$field)) {
                $time = (string) $row->$field;
                if (! preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time)) {
                    return 'INVALID_TIME_FORMAT:'.$field;
                }
                $time = strlen($time) === 5 ? $time.':00' : $time;
                if ($time < $start || $time > $end) {
                    return 'BUSINESS_TIME_OUTSIDE_CANDIDATE_SEGMENT:'.$field;
                }
            }
        }
        foreach (['regular_minutes' => ['regular_morning', 'regular_afternoon'], 'lunch_minutes' => ['overtime_lunch'],
            'ot_afternoon_minutes' => ['overtime_afternoon'], 'ot_evening_minutes' => ['overtime_evening']] as $field => $kinds) {
            if (! empty($row->$field) && ! collect($kinds)->contains(fn ($kind) => ! empty($row->{$kind.'_start'}) && ! empty($row->{$kind.'_end'}))) {
                return 'DURATION_WITHOUT_ALLOCATED_ENDPOINTS:'.$field;
            }
        }

        return null;
    }

    public function plan(object $row, object $target, ?int $actor, string $now): void
    {
        if (! $this->lock) {
            throw new \LogicException('Read-only canonical snapshot cannot plan writes.');
        }

        $sourceRow = $this->sourceRow($row, $target);
        $sourceKey = $this->key($row->machine_id, $row->work_date, $sourceRow->machine_assignment_id);
        $targetKey = $this->key($row->machine_id, $row->work_date, $target->id);
        $sourceId = $this->byScope[$sourceKey][0] ?? null;
        $targetId = $this->byScope[$targetKey][0] ?? null;
        if (! $sourceId || $sourceId === $targetId || ($target->id !== null && $this->emptyCase($sourceId) && $targetId && ! $this->emptyCase($targetId))) {
            return;
        }
        if ($targetId) {
            $this->deletes[$targetId] = true;
        }
        $case = $this->cases[$sourceId];
        $changes = ['machine_assignment_id' => $target->id, 'scope_key' => $target->id === null ? 'machine:'.$row->machine_id.'|date:'.$row->work_date.'|assignment:unresolved' : 'assignment:'.$target->id.'|date:'.$row->work_date];
        $this->updates[$sourceId] = $changes;
        foreach ($this->jobs[$sourceId] ?? [] as $job) {
            // Preserve opaque JSON object/list types outside the relationship metadata.
            $metadata = json_decode($job->daily_metadata ?? 'null');
            if (isset($metadata->case_materialization)
                && (is_object($metadata->case_materialization) || is_array($metadata->case_materialization))) {
                $links = (object) $metadata->case_materialization;
                $links->machine_assignment_id = $target->id;
                $links->scope_key = $changes['scope_key'];
                if (isset($links->assignment_resolution_status)) {
                    $links->assignment_resolution_status = $target->id === null ? 'NOT_FOUND' : 'MATCHED';
                }
                $links->candidate_machine_assignment_ids = $target->id === null ? [] : [$target->id];
                $metadata->case_materialization = $links;
                $this->jobUpdates[$job->id] = ['daily_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)];
            }
        }
        $this->logs[] = ['user_id' => $actor, 'machine_id' => $row->machine_id,
            'subject_type' => \App\Models\DailyPhotoCase::class, 'subject_id' => $sourceId,
            'event' => 'reconciliation.canonical_relinked', 'description' => 'Phục hồi relationship canonical theo lịch hiệu lực; giữ nguyên ảnh và pairing.',
            'properties' => json_encode(['work_date' => $row->work_date, 'row_id' => $row->id ?? null,
                'old' => ['machine_assignment_id' => $case->machine_assignment_id, 'scope_key' => $case->scope_key],
                'new' => $changes, 'removed_empty_case_id' => $targetId], JSON_THROW_ON_ERROR),
            'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        unset($this->byScope[$sourceKey]);
        $this->byScope[$targetKey] = [$sourceId];
        $case->machine_assignment_id = $target->id;
        $case->scope_key = $changes['scope_key'];
    }

    public function flush(string $now): void
    {
        if (! $this->lock) {
            throw new \LogicException('Read-only canonical snapshot cannot flush writes.');
        }

        foreach (array_chunk(array_keys($this->deletes), 250) as $ids) {
            DB::table('daily_photo_cases')->whereIn('id', $ids)->delete();
        }
        RelationshipBatchWriter::update('daily_photo_cases', $this->updates, $now);
        RelationshipBatchWriter::update('ocr_jobs', $this->jobUpdates, $now);
        foreach (array_chunk($this->logs, 100) as $logs) {
            DB::table('activity_logs')->insert($logs);
        }
    }

    public function caseRows(): array
    {
        return array_values($this->cases);
    }

    public function touchesWindow(object $case, string $from, ?string $to): bool
    {
        $stamps = [];
        foreach ($this->members[$case->id] ?? [] as $member) {
            if ($member->capture_datetime !== null) {
                $stamps[] = $member->capture_datetime;
            }
        }
        foreach ($this->caseIntervals[$case->id] ?? [] as $interval) {
            $stamps[] = $interval->raw_start_at;
            $stamps[] = $interval->raw_end_at;
        }
        $start = $stamps ? min($stamps) : $case->work_date.' 00:00:00';
        $end = $stamps ? max($stamps) : $case->work_date.' 23:59:59';

        return $end >= (strlen($from) > 10 ? $from : $from.' 00:00:00')
            && (! $to || $start < (strlen($to) > 10 ? $to : $to.' 23:59:59'));
    }

    private function populatedDayCases(object $row): array
    {
        return array_values(array_filter($this->byDay[$row->machine_id.'|'.$row->work_date] ?? [], fn ($case) => ! isset($this->deletes[$case->id])
            && (int) $case->machine_id === (int) $row->machine_id && $case->work_date === $row->work_date
            && ! $this->emptyCase($case->id)));
    }

    private function sourceRow(object $row, object $target): object
    {
        if (($target->ownership_policy ?? null) !== 'BUSINESS_DAY' || $target->id === null) {
            return $row;
        }
        $cases = $this->populatedDayCases($row);
        if (count($cases) === 1) {
            $source = clone $row;
            $source->machine_assignment_id = $cases[0]->machine_assignment_id;

            return $source;
        }

        return $row;
    }

    public function needsRelink(object $row, object $target): bool
    {
        $source = $this->sourceRow($row, $target);

        return $this->hasContent($source) && (int) $source->machine_assignment_id !== (int) $target->id;
    }

    private function emptyCase(int $id): bool
    {
        $case = $this->cases[$id];
        if ($case->status !== 'COLLECTING' || $case->pairing_computed_at !== null
            || ! in_array($case->source_metadata, [null, '', '[]', '{}'], true)
            || ! in_array($case->pairing_diagnostics, [null, '', '[]', '{}'], true)) {
            return false;
        }
        if (! empty($this->members[$id]) || ! empty($this->jobs[$id])) {
            return false;
        }

        return empty($this->caseIntervals[$id]);
    }

    private function containsStamp(object $target, string $stamp, bool $intervalEnd = false): bool
    {
        if (($target->ownership_policy ?? null) === 'BUSINESS_DAY') {
            return $stamp >= (string) $target->time_in && (! $target->time_out
                || ($intervalEnd ? $stamp <= (string) $target->time_out : $stamp < (string) $target->time_out));
        }

        return $stamp >= (string) $target->time_in && (! $target->time_out
            || (($target->id !== null || $intervalEnd) ? $stamp <= (string) $target->time_out : $stamp < (string) $target->time_out));
    }

    private function key(int $machine, string $date, mixed $assignment): string
    {
        return $machine.'|'.$date.'|'.$assignment;
    }
}
