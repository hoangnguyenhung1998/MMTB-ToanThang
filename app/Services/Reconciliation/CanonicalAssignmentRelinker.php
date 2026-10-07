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
        if ($sourceId && ! $this->emptyCase($sourceId)) {
            if (isset($this->blocked[$sourceKey]) || isset($this->blocked[$targetKey])) {
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
                if (! $this->containsStamp($target, $interval->raw_start_at) || ! $this->containsStamp($target, $interval->raw_end_at)) {
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

    public function plan(object $row, object $target, ?int $actor, string $now): void
    {
        $sourceKey = $this->key($row->machine_id, $row->work_date, $row->machine_assignment_id);
        $targetKey = $this->key($row->machine_id, $row->work_date, $target->id);
        $sourceId = $this->byScope[$sourceKey][0] ?? null;
        $targetId = $this->byScope[$targetKey][0] ?? null;
        if (! $sourceId || $sourceId === $targetId || $this->emptyCase($sourceId)) {
            return;
        }
        if ($targetId) {
            $this->deletes[$targetId] = true;
        }
        $case = $this->cases[$sourceId];
        $changes = ['machine_assignment_id' => $target->id, 'scope_key' => 'assignment:'.$target->id.'|date:'.$row->work_date];
        $this->updates[$sourceId] = $changes;
        foreach ($this->jobs[$sourceId] ?? [] as $job) {
            $metadata = json_decode($job->daily_metadata ?? 'null', true);
            if (isset($metadata['case_materialization'])) {
                $metadata['case_materialization']['machine_assignment_id'] = $target->id;
                $metadata['case_materialization']['scope_key'] = $changes['scope_key'];
                $metadata['case_materialization']['candidate_machine_assignment_ids'] = [$target->id];
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

    private function containsStamp(object $target, string $stamp): bool
    {
        return $stamp >= (string) $target->time_in && (! $target->time_out || $stamp <= (string) $target->time_out);
    }

    private function key(int $machine, string $date, mixed $assignment): string
    {
        return $machine.'|'.$date.'|'.$assignment;
    }
}
