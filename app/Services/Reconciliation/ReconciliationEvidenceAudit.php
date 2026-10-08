<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\DB;

/** SELECT-only evidence packet. Never exports images, paths, text, raw OCR or coordinates. */
class ReconciliationEvidenceAudit
{
    public function collect(ReconciliationPeriod $period, array $groups): array
    {
        $machineIds = array_unique(array_column($groups, 'machine_id'));
        $dates = array_unique(array_column($groups, 'work_date'));
        $rowIds = collect($groups)->flatMap(fn ($g) => array_column($g['rows'], 'row_id'))->all();
        $rows = DB::table('reconciliation_rows')->whereIn('id', $rowIds)->get()->keyBy('id');
        $decode = fn ($value) => json_decode($value ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?? [];
        $jobIds = $rows->flatMap(fn ($r) => $decode($r->daily_ocr_job_ids))->unique()->all();
        $intervalIds = $rows->flatMap(fn ($r) => collect($decode($r->daily_intervals))->pluck('canonical_interval_id'))->filter()->unique()->all();
        $intervals = DB::table('daily_photo_intervals')->whereIn('id', $intervalIds)->get()->keyBy('id');
        $journalIds = $rows->flatMap(fn ($r) => $decode($r->journal_row_ids))->unique()->all();
        $journals = DB::table('journal_rows')->whereIn('id', $journalIds)->get();
        $referenceJobs = DB::table('ocr_jobs')->whereIn('id', $jobIds)->get()->keyBy('id');
        $caseIds = $intervals->pluck('daily_photo_case_id')->merge($referenceJobs->pluck('daily_photo_case_id'))->filter()->unique();
        $cases = DB::table('daily_photo_cases')->where(function ($q) use ($machineIds, $dates, $caseIds) {
            $q->whereIn('id', $caseIds)->orWhere(function ($q) use ($machineIds, $dates) {
                $q->whereIn('machine_id', $machineIds)->whereIn(DB::raw('DATE(work_date)'), $dates);
            });
        })->get()->keyBy('id');
        $members = DB::table('daily_photo_case_evidence')->whereIn('daily_photo_case_id', $cases->keys())->get();
        $allIntervals = DB::table('daily_photo_intervals')->whereIn('daily_photo_case_id', $cases->keys())->get();
        $jobs = DB::table('ocr_jobs')->where(function ($q) use ($cases, $members, $jobIds) {
            $q->whereIn('daily_photo_case_id', $cases->keys())->orWhereIn('id', $members->pluck('ocr_job_id')->merge($jobIds));
        })->get()->keyBy('id');
        $assignments = DB::table('machine_assignments as a')->leftJoin('machine_assignment_bch_resolutions as b', 'b.machine_assignment_id', '=', 'a.id')
            ->whereIn('a.machine_id', $machineIds)->get(['a.id', 'a.machine_id', 'a.project_id', 'a.command_center_id', 'a.time_in', 'a.time_out', 'b.command_center_id as resolved_bch_id']);
        $events = DB::table('machine_events')->whereIn('machine_id', $machineIds)->get(['id', 'machine_id', 'type', 'occurred_at']);
        $shared = DB::table('reconciliation_rows as r')->join('reconciliation_periods as p', 'p.id', '=', 'r.reconciliation_period_id')
            ->whereIn('r.machine_id', $machineIds)->whereIn(DB::raw('DATE(r.work_date)'), $dates)
            ->get(['r.id', 'r.reconciliation_period_id', 'r.machine_id', 'r.work_date', 'r.machine_assignment_id', 'r.status',
                'r.manually_edited_at', 'r.reviewed_at', 'r.confirmed_at', 'r.reviewed_by', 'r.confirmed_by',
                'r.daily_ocr_job_ids', 'r.daily_intervals', 'p.status as period_status']);
        $classifier = new ReconciliationDuplicateClassifier;
        $casesByDay = $cases->groupBy(fn ($case) => $case->machine_id.'|'.substr($case->work_date, 0, 10));
        $jobsByCase = $jobs->groupBy('daily_photo_case_id');
        $protectedDays = [];
        foreach ($shared as $sharedRow) {
            if ($classifier->protected($sharedRow) || ! in_array($sharedRow->period_status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)) {
                $protectedDays[$sharedRow->machine_id.'|'.substr($sharedRow->work_date, 0, 10)] = true;
            }
        }
        $details = [];
        $counts = ['SAFE' => 0, 'HUMAN_REVIEW' => 0, 'UNSAFE' => 0];
        $referenceCounts = ['OCR' => 0, 'INTERVAL' => 0, 'owner_compatible_stored_mismatch' => 0, 'owner_conflict' => 0, 'missing' => 0];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $summary) {
                $row = $rows[$summary['row_id']];
                $issues = [];
                foreach ($decode($row->daily_ocr_job_ids) as $id) {
                    $job = $jobs->get($id);
                    $case = $job ? $cases->get($job->daily_photo_case_id) : null;
                    $issues[] = $this->reference('OCR', $id, $row, $group['owner_assignment_id'], $job, $case);
                }
                foreach ($decode($row->daily_intervals) as $part) {
                    if ($id = $part['canonical_interval_id'] ?? null) {
                        $interval = $intervals->get($id);
                        $issues[] = $this->reference('INTERVAL', $id, $row, $group['owner_assignment_id'], $interval,
                            $interval ? $cases->get($interval->daily_photo_case_id) : null);
                    }
                }
                foreach ($issues as $issue) {
                    if (! $issue['matches_stored_row']) {
                        $referenceCounts[$issue['kind']]++;
                        $referenceCounts[$issue['matches_daily_owner'] ? 'owner_compatible_stored_mismatch' : 'owner_conflict']++;
                    }
                    if (! $issue['reference_exists'] || ! $issue['case_exists']) {
                        $referenceCounts['missing']++;
                    }
                }
                $dayKey = $row->machine_id.'|'.$group['work_date'];
                $scopeJobIds = collect($decode($row->daily_ocr_job_ids));
                foreach ($casesByDay->get($dayKey, collect()) as $scopeCase) {
                    $scopeJobIds = $scopeJobIds->merge($jobsByCase->get($scopeCase->id, collect())->pluck('id'));
                }
                $scopeJobs = $jobs->only($scopeJobIds->unique()->all());
                $protected = $summary['protected'] || ! in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)
                    || $scopeJobs->contains(fn ($j) => $this->protectedJob($j)) || isset($protectedDays[$dayKey]);
                $owner = $assignments->firstWhere('id', $group['owner_assignment_id']);
                $ownershipMismatch = $row->machine_assignment_id !== $group['owner_assignment_id']
                    || ($owner && ((int) $row->project_id !== (int) $owner->project_id
                        || (int) $row->command_center_id !== (int) $group['owner_bch_id']));
                $referenceConflict = collect($issues)->contains(fn ($i) => ! $i['matches_stored_row'] || ! $i['matches_daily_owner']);
                $missing = collect($issues)->contains(fn ($i) => ! $i['reference_exists'] || ! $i['case_exists']);
                // SAFE is only a candidate for the existing guarded preview, never permission to write.
                $disposition = $missing ? 'UNSAFE' : (($protected || $ownershipMismatch || $referenceConflict || $group['pairs'] || $summary['canonical_reason'] || $group['ownership_reason']) ? 'HUMAN_REVIEW' : 'SAFE');
                $counts[$disposition]++;
                $numericTime = array_filter((array) $row, fn ($field) => preg_match('/^(regular|overtime|daily|gps|ot|lunch|confirmed|rounded)_.*(start|end|minutes|hours|check_in|check_out)$/', $field), ARRAY_FILTER_USE_KEY);
                $details[] = ['row_id' => $row->id, 'machine_id' => $row->machine_id, 'work_date' => $group['work_date'],
                    'assignment_id' => $row->machine_assignment_id, 'owner_assignment_id' => $group['owner_assignment_id'],
                    'disposition' => $disposition, 'protected' => (bool) $protected,
                    'status' => $row->status, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at, 'time' => $numericTime,
                    'segment_start' => $row->segment_start, 'segment_end' => $row->segment_end,
                    'journal_row_ids' => $decode($row->journal_row_ids), 'daily_ocr_job_ids' => $decode($row->daily_ocr_job_ids),
                    'daily_intervals' => array_map(fn ($part) => $this->safeInterval($part), $decode($row->daily_intervals)),
                    'references' => $issues];
            }
        }

        return ['mode' => 'SELECT_ONLY', 'disposition_counts_by_row' => $counts, 'reference_mismatches' => $referenceCounts, 'rows' => $details,
            'assignments' => $assignments->all(), 'events' => $events->all(),
            'journal_rows' => $journals->map(fn ($j) => $this->only($j, ['id', 'journal_document_id', 'machine_id', 'machine_assignment_id', 'work_date', 'start_time', 'end_time', 'check_in', 'check_out', 'regular_minutes', 'overtime_minutes', 'quantity', 'status', 'reviewed_at', 'reviewed_by']))->all(),
            'cases' => $cases->map(fn ($c) => $this->only($c, ['id', 'machine_id', 'machine_assignment_id', 'work_date', 'scope_key', 'status', 'pairing_computed_at', 'created_at', 'updated_at']))->values()->all(),
            'memberships' => $members->map(fn ($m) => $this->only($m, ['id', 'daily_photo_case_id', 'ocr_job_id', 'capture_datetime', 'assignment_resolution_status', 'pairing_state']))->all(),
            'canonical_intervals' => $allIntervals->map(fn ($i) => $this->only($i, ['id', 'daily_photo_case_id', 'sequence', 'start_evidence_id', 'end_evidence_id', 'raw_start_at', 'raw_end_at', 'policy_version']))->all(),
            'ocr_jobs' => $jobs->map(function ($j) {
                $data = $this->only($j, ['id', 'machine_id', 'extracted_date', 'extracted_time', 'daily_photo_case_id', 'status', 'review_status', 'reviewed_at', 'machine_resolution_method', 'ocr_final_source', 'created_at', 'updated_at']);
                $metadata = json_decode($j->daily_metadata ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                $data['materialized_relationship'] = array_intersect_key($metadata['case_materialization'] ?? [], array_flip(['daily_photo_case_id', 'daily_photo_case_evidence_id', 'machine_assignment_id', 'scope_key', 'assignment_resolution_status', 'candidate_machine_assignment_ids']));
                $data['protected'] = $this->protectedJob($j);

                return $data;
            })->values()->all(),
            'shared_rows' => $shared->map(fn ($r) => $this->only($r, ['id', 'reconciliation_period_id', 'machine_id', 'work_date', 'machine_assignment_id', 'status', 'period_status', 'manually_edited_at', 'reviewed_at', 'confirmed_at', 'reviewed_by', 'confirmed_by']))->all()];
    }

    private function reference(string $kind, int $id, object $row, ?int $owner, ?object $reference, ?object $case): array
    {
        $sameDay = $case && (int) $case->machine_id === (int) $row->machine_id && substr($case->work_date, 0, 10) === substr($row->work_date, 0, 10);
        $sourceMatches = $kind !== 'OCR' || ($reference && (int) $reference->machine_id === (int) $row->machine_id
            && ($reference->extracted_date === null || substr($reference->extracted_date, 0, 10) === substr($row->work_date, 0, 10)));

        return ['kind' => $kind, 'id' => $id, 'reference_exists' => $reference !== null, 'case_exists' => $case !== null,
            'case_id' => $case?->id, 'case_assignment_id' => $case?->machine_assignment_id,
            'matches_stored_row' => (bool) ($sameDay && $sourceMatches && $case->machine_assignment_id === $row->machine_assignment_id),
            'matches_daily_owner' => (bool) ($sameDay && $sourceMatches && $case->machine_assignment_id === $owner)];
    }

    private function protectedJob(object $job): bool
    {
        return $job->reviewed_at !== null || $job->machine_resolution_method === 'HUMAN'
            || $job->ocr_final_source === 'MANUAL' || in_array($job->review_status, ['APPROVED', 'CORRECTED'], true);
    }

    private function only(object $row, array $fields): array
    {
        return array_intersect_key((array) $row, array_flip($fields));
    }

    private function safeInterval(array $part): array
    {
        $fields = ['canonical_interval_id', 'source', 'start', 'end', 'start_time', 'end_time', 'start_date', 'end_date', 'raw_start_at', 'raw_end_at',
            'start_job_id', 'end_job_id', 'regular_minutes', 'overtime_minutes', 'minutes', 'kind', 'policy_version'];

        return ['values' => array_intersect_key($part, array_flip($fields)),
            'omitted_fields' => array_values(array_diff(array_keys($part), $fields))];
    }
}
