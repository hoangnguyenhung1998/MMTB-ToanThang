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

    private array $protectedRows = [];

    private array $updates = [];

    private array $deletes = [];

    private array $jobUpdates = [];

    private array $logs = [];

    public function __construct(array $machineIds, string $from, string $to, private readonly bool $lock = true, array $referenceJobIds = [], private readonly bool $planning = false)
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
            ->select(['r.*', 'p.status as protection_period_status', 'p.updated_at as protection_period_updated_at'])->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $this->protectedRows = $protected->keyBy('id')->all();
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
        if ($sourceId && ($sourceId !== $targetId || $this->metadataNeedsRepair($sourceId, $target)) && (isset($this->blocked[$sourceKey]) || isset($this->blocked[$targetKey]) || isset($this->blockedCases[$sourceId]) || isset($this->blockedCases[$targetId]))) {
            return 'PROTECTED_CANONICAL_RELATIONSHIP';
        }
        if ($sourceId && ($sourceId !== $targetId || $this->metadataNeedsRepair($sourceId, $target))) {
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
                if ($job->extracted_date !== null && substr($job->extracted_date, 0, 10) !== $row->work_date) {
                    return 'CANONICAL_OCR_CONFLICT';
                }
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
        if (! $this->lock && ! $this->planning) {
            throw new \LogicException('Read-only canonical snapshot cannot plan writes.');
        }

        $sourceRow = $this->sourceRow($row, $target);
        $sourceKey = $this->key($row->machine_id, $row->work_date, $sourceRow->machine_assignment_id);
        $targetKey = $this->key($row->machine_id, $row->work_date, $target->id);
        $sourceId = $this->byScope[$sourceKey][0] ?? null;
        $targetId = $this->byScope[$targetKey][0] ?? null;
        if ($sourceId && $sourceId === $targetId) {
            if ($this->reason($row, $target) !== null) {
                return;
            }
            foreach ($this->jobs[$sourceId] ?? [] as $job) {
                $metadata = $this->repairedMetadata($job, $sourceId, $target);
                if ($metadata !== null) {
                    $this->jobUpdates[$job->id] = ['daily_metadata' => $metadata];
                    $this->logs[] = ['user_id' => $actor, 'machine_id' => $row->machine_id,
                        'subject_type' => \App\Models\OcrJob::class, 'subject_id' => $job->id,
                        'event' => 'reconciliation.ocr_relationship_metadata_repaired',
                        'description' => 'Repair OCR relationship metadata; preserve source evidence and pairing.',
                        'properties' => json_encode(['case_id' => $sourceId, 'assignment_id' => $target->id,
                            'old_relationship' => $this->relationshipForAudit($job->daily_metadata),
                            'new_relationship' => $this->relationshipForAudit($metadata)], JSON_THROW_ON_ERROR),
                        'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now];
                    $job->daily_metadata = $metadata;
                }
            }

            return;
        }
        if (! $sourceId || ($target->id !== null && $this->emptyCase($sourceId) && $targetId && ! $this->emptyCase($targetId))) {
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
                $job->daily_metadata = $this->jobUpdates[$job->id]['daily_metadata'];
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

    public function flush(string $now, ?string $runId = null): void
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
            if ($runId !== null) {
                foreach ($logs as &$log) {
                    $properties = json_decode($log['properties'], true, 512, JSON_THROW_ON_ERROR);
                    $log['properties'] = json_encode($properties + ['repair_run_id' => $runId], JSON_THROW_ON_ERROR);
                }
                unset($log);
            }
            DB::table('activity_logs')->insert($logs);
        }
    }

    public function snapshotFingerprint(): string
    {
        $data = [$this->cases, $this->members, $this->intervals, $this->jobsById, $this->blocked, $this->blockedCases, $this->protectedRows];

        return ReconciliationRepairSnapshot::hash($data);
    }

    /** Ownership does not require every valid capture to be an interval endpoint.
     * Validate the entire union against actual jobs/members and every canonical interval.
     * The legacy strict pairing proof remains available for other duplicate policies.
     */
    public function provesOwnershipSource(object $rich, object $shadow, object $owner, array $changes): bool
    {
        $combined = clone $rich;
        foreach ($changes as $field => $value) {
            $combined->$field = $value;
        }
        $references = clone $shadow;
        $references->daily_ocr_job_ids = $combined->daily_ocr_job_ids;

        return $this->provesSameOcrSource($combined, $references, $owner, false);
    }

    /** Prove actual source/pairing, never infer equivalence from hashes or job IDs alone. */
    public function provesSameOcrSource(object $rich, object $shadow, object $owner, bool $requireAllPaired = true): bool
    {
        if ($this->protectedEvidence($rich) || $this->protectedEvidence($shadow)
            || $this->reason($rich, $owner) || $this->reason($shadow, $owner)) {
            return false;
        }
        $ids = json_decode($rich->daily_ocr_job_ids ?? '[]', true) ?? [];
        $other = json_decode($shadow->daily_ocr_job_ids ?? '[]', true) ?? [];
        sort($ids);
        sort($other);
        if (! $ids || $ids !== $other || count($ids) !== count(array_unique($ids))) {
            return false;
        }
        $caseIds = [];
        foreach ($ids as $id) {
            $job = $this->jobsById[$id] ?? null;
            if (! $job || $job->status !== 'COMPLETED' || $job->document_type !== 'DAILY_TIMEMARK'
                || (int) $job->machine_id !== (int) $rich->machine_id || $job->extracted_date === null
                || substr($job->extracted_date, 0, 10) !== $rich->work_date || ! $job->daily_photo_case_id) {
                return false;
            }
            $caseIds[$job->daily_photo_case_id] = true;
        }
        if (count($caseIds) !== 1) {
            return false;
        }
        $caseId = array_key_first($caseIds);
        $case = $this->cases[$caseId] ?? null;
        if (! $case || isset($this->blockedCases[$caseId])
            || isset($this->blocked[$this->key($rich->machine_id, $rich->work_date, $case->machine_assignment_id)])
            || (int) $case->machine_id !== (int) $rich->machine_id || $case->work_date !== $rich->work_date) {
            return false;
        }
        $members = [];
        foreach ($this->members[$caseId] ?? [] as $member) {
            $job = $this->jobsById[$member->ocr_job_id] ?? null;
            if (! $job || ! $job->extracted_time || $member->capture_datetime !== $rich->work_date.' '.substr($job->extracted_time, 0, 8)) {
                return false;
            }
            $members[$member->id] = $member;
        }
        $memberJobs = array_column($members, 'ocr_job_id');
        sort($memberJobs);
        if ($memberJobs !== $ids) {
            return false;
        }
        $parts = json_decode($rich->daily_intervals ?? '[]', true) ?? [];
        $selected = [];
        $pairedJobs = [];
        foreach ($parts as $part) {
            $id = $part['canonical_interval_id'] ?? null;
            $interval = $this->intervals[$id] ?? null;
            $start = $interval ? ($members[$interval->start_evidence_id] ?? null) : null;
            $end = $interval ? ($members[$interval->end_evidence_id] ?? null) : null;
            if (! $interval || (int) $interval->daily_photo_case_id !== (int) $caseId || ! $start || ! $end
                || ! $interval->raw_start_at || ! $interval->raw_end_at || $interval->raw_start_at >= $interval->raw_end_at
                || $start->capture_datetime !== $interval->raw_start_at || $end->capture_datetime !== $interval->raw_end_at) {
                return false;
            }
            foreach (['start_job_id' => $start->ocr_job_id, 'end_job_id' => $end->ocr_job_id] as $field => $expected) {
                if (isset($part[$field]) && (int) $part[$field] !== (int) $expected) {
                    return false;
                }
            }
            foreach (['start' => $interval->raw_start_at, 'end' => $interval->raw_end_at] as $field => $stamp) {
                foreach ([$field, $field.'_time'] as $timeField) {
                    if (isset($part[$timeField]) && substr($part[$timeField].':00', 0, 8) !== substr($stamp, 11, 8)) {
                        return false;
                    }
                }
                if (isset($part[$field.'_date']) && $part[$field.'_date'] !== substr($stamp, 0, 10)) {
                    return false;
                }
                if (isset($part['raw_'.$field.'_at']) && $part['raw_'.$field.'_at'] !== $stamp) {
                    return false;
                }
            }
            $selected[] = $id;
            $pairedJobs[] = $start->ocr_job_id;
            $pairedJobs[] = $end->ocr_job_id;
        }
        $all = array_column($this->caseIntervals[$caseId] ?? [], 'id');
        sort($selected);
        sort($all);
        $pairedJobs = array_values(array_unique($pairedJobs));
        sort($pairedJobs);

        return $selected !== [] && $selected === $all && (! $requireAllPaired || $pairedJobs === $ids);
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

        $sourceId = $this->byScope[$this->key($row->machine_id, $row->work_date, $source->machine_assignment_id)][0] ?? null;

        return $this->hasContent($source) && ((int) $source->machine_assignment_id !== (int) $target->id
            || ($sourceId && $this->metadataNeedsRepair($sourceId, $target)));
    }

    private function relationshipForAudit(string $metadata): array
    {
        $links = (array) json_decode($metadata, false, 512, JSON_THROW_ON_ERROR)->case_materialization;

        return array_intersect_key($links, array_flip(['machine_assignment_id', 'scope_key', 'daily_photo_case_id',
            'assignment_resolution_status', 'candidate_machine_assignment_ids']));
    }

    private function metadataNeedsRepair(int $caseId, object $target): bool
    {
        foreach ($this->jobs[$caseId] ?? [] as $job) {
            if ($this->repairedMetadata($job, $caseId, $target) !== null) {
                return true;
            }
        }

        return false;
    }

    private function repairedMetadata(object $job, int $caseId, object $target): ?string
    {
        $metadata = json_decode($job->daily_metadata ?? 'null');
        if (! is_object($metadata) || ! isset($metadata->case_materialization)
            || ! is_object($metadata->case_materialization)) {
            return null;
        }
        $before = json_encode($metadata, JSON_THROW_ON_ERROR);
        $links = $metadata->case_materialization;
        $links->machine_assignment_id = $target->id;
        $links->scope_key = $this->cases[$caseId]->scope_key;
        if (property_exists($links, 'daily_photo_case_id')) {
            $links->daily_photo_case_id = $caseId;
        }
        if (property_exists($links, 'assignment_resolution_status')) {
            $links->assignment_resolution_status = $target->id === null ? 'NOT_FOUND' : 'MATCHED';
        }
        if (property_exists($links, 'candidate_machine_assignment_ids')) {
            $links->candidate_machine_assignment_ids = $target->id === null ? [] : [$target->id];
        }
        $after = json_encode($metadata, JSON_THROW_ON_ERROR);

        return $before === $after ? null : $after;
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
