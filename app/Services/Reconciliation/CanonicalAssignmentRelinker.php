<?php

namespace App\Services\Reconciliation;

use Illuminate\Support\Facades\DB;

/** Relationship-only plans: no materialization, pairing or OCR is invoked. */
class CanonicalAssignmentRelinker
{
    private array $cases = [];

    private array $byScope = [];

    private array $members = [];

    private array $intervals = [];

    private array $caseIntervals = [];

    private array $jobs = [];

    private array $jobsById = [];

    private array $blocked = [];

    private array $updates = [];

    private array $deletes = [];

    private array $jobUpdates = [];

    private array $logs = [];

    public function __construct(array $machineIds, string $from, string $to)
    {
        $cases = DB::table('daily_photo_cases')->whereIn('machine_id', $machineIds)
            ->whereBetween('work_date', [$from, $to.' 23:59:59'])->orderBy('id')->lockForUpdate()->get();
        foreach ($cases as $case) {
            $case->work_date = substr($case->work_date, 0, 10);
            $this->cases[$case->id] = $case;
            $this->byScope[$this->key($case->machine_id, $case->work_date, $case->machine_assignment_id)][] = $case->id;
        }
        if ($cases->isEmpty()) {
            return;
        }
        $ids = $cases->pluck('id')->all();
        $this->members = DB::table('daily_photo_case_evidence')->whereIn('daily_photo_case_id', $ids)
            ->lockForUpdate()->get()->groupBy('daily_photo_case_id')->all();
        $this->intervals = DB::table('daily_photo_intervals')->whereIn('daily_photo_case_id', $ids)
            ->lockForUpdate()->get()->keyBy('id')->all();
        foreach ($this->intervals as $interval) {
            $this->caseIntervals[$interval->daily_photo_case_id][] = $interval;
        }
        $memberJobIds = collect($this->members)->flatten(1)->pluck('ocr_job_id')->all();
        $jobs = DB::table('ocr_jobs')->where(fn ($q) => $q->whereIn('daily_photo_case_id', $ids)->orWhereIn('id', $memberJobIds))
            ->lockForUpdate()->get();
        $this->jobs = $jobs->groupBy('daily_photo_case_id')->all();
        $this->jobsById = $jobs->keyBy('id')->all();
        // A shared canonical case cannot be moved underneath a locked historical period/row.
        $protected = DB::table('reconciliation_rows as r')->join('reconciliation_periods as p', 'p.id', '=', 'r.reconciliation_period_id')
            ->whereIn('r.machine_id', $machineIds)->whereBetween('r.work_date', [$from, $to.' 23:59:59'])
            ->where(fn ($q) => $q->whereNotIn('p.status', ['DRAFT', 'GENERATED', 'REVIEWING'])
                ->orWhere('r.status', '!=', 'DRAFT')->orWhereNotNull('r.reviewed_at')->orWhereNotNull('r.confirmed_at'))
            ->select(['r.machine_id', 'r.work_date', 'r.machine_assignment_id'])->lockForUpdate()->get();
        foreach ($protected as $row) {
            $this->blocked[$this->key($row->machine_id, substr($row->work_date, 0, 10), $row->machine_assignment_id)] = true;
        }
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
        $sourceKey = $this->key($row->machine_id, $row->work_date, $row->machine_assignment_id);
        $targetKey = $this->key($row->machine_id, $row->work_date, $target->id);
        $sourceIds = $this->byScope[$sourceKey] ?? [];
        $targetIds = $this->byScope[$targetKey] ?? [];
        if (count($sourceIds) > 1 || count($targetIds) > 1) {
            return 'CANONICAL_CONFLICT';
        }
        $sourceId = $sourceIds[0] ?? null;
        $targetId = $targetIds[0] ?? null;
        if ($target->id === null && $sourceId !== $targetId) {
            if ($sourceId && (isset($this->blocked[$sourceKey]) || isset($this->blocked[$targetKey]))) {
                return 'PROTECTED_CANONICAL_RELATIONSHIP';
            }
            if ($targetId && ! $this->emptyCase($targetId)) {
                return 'CANONICAL_CONFLICT';
            }
        }
        if ($sourceId && ! $this->emptyCase($sourceId)) {
            if ($sourceId !== $targetId && (isset($this->blocked[$sourceKey]) || isset($this->blocked[$targetKey]))) {
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
        $ids = $this->byScope[$this->key($row->machine_id, $row->work_date, $row->machine_assignment_id)] ?? [];
        if (count($ids) !== 1 || empty($this->caseIntervals[$ids[0]]) || ! empty(json_decode($row->journal_row_ids ?? '[]', true))) {
            return false;
        }
        [$start, $end] = AssignmentInterval::segment($target, $row->work_date);
        $start = max($start, $row->segment_start ?: $start);
        $end = min($end, $row->segment_end ?: $end);
        if ($start >= $end) {
            return false;
        }
        $bounded = (object) ['id' => $target->id, 'time_in' => $row->work_date.' '.$start, 'time_out' => $row->work_date.' '.$end];
        if ($this->reason($row, $bounded) !== null) {
            return false;
        }
        $knownJobs = array_map(fn ($job) => (int) $job->id, ($this->jobs[$ids[0]] ?? collect())->all());
        foreach (json_decode($row->daily_ocr_job_ids ?? '[]', true) ?? [] as $id) {
            if (! in_array((int) $id, $knownJobs, true)) {
                return false;
            }
        }
        foreach (json_decode($row->daily_intervals ?? '[]', true) ?? [] as $part) {
            foreach (['start', 'end'] as $endpoint) {
                if (! empty($part[$endpoint])) {
                    $time = (string) $part[$endpoint];
                    $time = strlen($time) === 5 ? $time.':00' : $time;
                    if (! preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) || $time < $start || $time > $end
                        || (! empty($part[$endpoint.'_date']) && $part[$endpoint.'_date'] !== $row->work_date)) {
                        return false;
                    }
                }
                if (! empty($part[$endpoint.'_job_id']) && ! in_array((int) $part[$endpoint.'_job_id'], $knownJobs, true)) {
                    return false;
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
                return false; // Overnight/split data is never inferred or copied.
            }
        }
        foreach ($fields as $field) {
            if (! empty($row->$field)) {
                $time = (string) $row->$field;
                if (! preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time)) {
                    return false;
                }
                $time = strlen($time) === 5 ? $time.':00' : $time;
                if ($time < $start || $time > $end) {
                    return false;
                }
            }
        }
        foreach (['regular_minutes' => ['regular_morning', 'regular_afternoon'], 'lunch_minutes' => ['overtime_lunch'],
            'ot_afternoon_minutes' => ['overtime_afternoon'], 'ot_evening_minutes' => ['overtime_evening']] as $field => $kinds) {
            if (! empty($row->$field) && ! collect($kinds)->contains(fn ($kind) => ! empty($row->{$kind.'_start'}) && ! empty($row->{$kind.'_end'}))) {
                return false;
            }
        }

        return true;
    }

    public function plan(object $row, object $target, ?int $actor, string $now): void
    {
        $sourceKey = $this->key($row->machine_id, $row->work_date, $row->machine_assignment_id);
        $targetKey = $this->key($row->machine_id, $row->work_date, $target->id);
        $sourceId = $this->byScope[$sourceKey][0] ?? null;
        $targetId = $this->byScope[$targetKey][0] ?? null;
        if (! $sourceId || $sourceId === $targetId || ($target->id !== null && $this->emptyCase($sourceId))) {
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
                if ($target->id === null && isset($links->assignment_resolution_status)) {
                    $links->assignment_resolution_status = 'NOT_FOUND';
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
        return $stamp >= (string) $target->time_in && (! $target->time_out
            || (($target->id !== null || $intervalEnd) ? $stamp <= (string) $target->time_out : $stamp < (string) $target->time_out));
    }

    private function key(int $machine, string $date, mixed $assignment): string
    {
        return $machine.'|'.$date.'|'.$assignment;
    }
}
