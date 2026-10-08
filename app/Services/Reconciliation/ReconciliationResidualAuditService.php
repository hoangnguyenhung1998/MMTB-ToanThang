<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\DB;

/** SELECT-only evidence collection. Never invokes Repair, sync, pairing or generation. */
class ReconciliationResidualAuditService
{
    public function audit(ReconciliationPeriod $period, array $focusIds): array
    {
        return DB::transaction(function () use ($period, $focusIds): array {
            $rows = DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)->orderBy('id')->get();
            foreach ($rows as $row) {
                $row->work_date = substr($row->work_date, 0, 10);
            }
            $selected = $rows->filter(fn ($r) => in_array((int) $r->id, $focusIds, true)
                || $r->machine_assignment_id === null || $r->project_id === null || $r->command_center_id === null);
            $machineIds = $selected->pluck('machine_id')->unique()->all();
            $machines = DB::table('machines')->whereIn('id', $machineIds)->get(['id', 'asset_code', 'chassis_no', 'status'])->keyBy('id');
            $history = DB::table('machine_assignments as a')
                ->leftJoin('machine_assignment_bch_resolutions as r', 'r.machine_assignment_id', '=', 'a.id')
                ->leftJoin('command_centers as b', 'b.id', '=', DB::raw('COALESCE(a.command_center_id, r.command_center_id)'))
                ->leftJoin('projects as p', 'p.id', '=', 'a.project_id')
                ->whereIn('a.machine_id', $machineIds)->orderBy('a.time_in')->orderBy('a.id')
                ->get(['a.*', 'b.id as source_bch_id', 'b.name as bch_name', 'p.id as source_project_id']);
            $events = DB::table('machine_events')->whereIn('machine_id', $machineIds)->orderBy('occurred_at')->orderBy('id')->get();
            $states = new AssignmentTimelineState($history, $events);
            $byMachine = $history->groupBy('machine_id');
            $bchNames = DB::table('command_centers')->whereIn('id', $rows->pluck('command_center_id')->filter()->unique())->pluck('name', 'id');
            $canonical = new CanonicalAssignmentRelinker($machineIds, $period->date_from->toDateString(), $period->date_to->toDateString(), false);
            $cases = collect($canonical->caseRows());
            $caseIds = $cases->pluck('id')->all();
            $members = DB::table('daily_photo_case_evidence')->whereIn('daily_photo_case_id', $caseIds)->orderBy('id')->get();
            $intervals = DB::table('daily_photo_intervals')->whereIn('daily_photo_case_id', $caseIds)->orderBy('id')->get();
            $jobIds = $selected->flatMap(fn ($r) => json_decode($r->daily_ocr_job_ids ?? '[]', true) ?? [])
                ->merge($members->pluck('ocr_job_id'))->unique()->all();
            $jobs = DB::table('ocr_jobs')->where(fn ($q) => $q->whereIn('daily_photo_case_id', $caseIds)->orWhereIn('id', $jobIds))->orderBy('id')->get();
            $attachments = DB::table('zalo_attachments')->whereIn('id', $jobs->pluck('zalo_attachment_id')->filter())->orderBy('id')
                ->get(['id', 'zalo_message_id', 'sha256', 'byte_size', 'mime_type']);
            $journalIds = $selected->flatMap(fn ($r) => json_decode($r->journal_row_ids ?? '[]', true) ?? [])->unique()->all();
            $journals = DB::table('journal_rows')->whereIn('id', $journalIds)->orderBy('id')->get();
            $sharedRows = DB::table('reconciliation_rows as r')->join('reconciliation_periods as p', 'p.id', '=', 'r.reconciliation_period_id')
                ->whereIn('r.machine_id', $machineIds)->whereBetween('r.work_date', [$period->date_from->toDateString(), $period->date_to->toDateString().' 23:59:59'])
                ->orderBy('r.id')->get(['r.*', 'p.status as period_status']);
            $audits = DB::table('activity_logs')->where('subject_type', \App\Models\ReconciliationRow::class)
                ->whereIn('subject_id', $selected->pluck('id'))->where('event', 'reconciliation.relationship_unassigned')
                ->orderBy('id')->get(['id', 'subject_id', 'occurred_at', 'properties']);
            $dailyRows = $rows->groupBy(fn ($r) => $r->machine_id.'|'.$r->work_date);
            $byContext = [];
            $details = [];
            foreach ($selected as $row) {
                $context = $states->context((int) $row->machine_id, $row->work_date.' '.($row->segment_start ?: '00:00:00'),
                    $row->work_date.' '.($row->segment_end ?: '23:59:59'));
                $state = $context['timeline_context'] ?? 'ASSIGNED_OR_CROSS_BOUNDARY';
                if ($row->machine_assignment_id === null) {
                    $byContext[$state] = ($byContext[$state] ?? 0) + 1;
                }
                $candidates = $byMachine->get($row->machine_id, collect())->filter(fn ($a) => AssignmentInterval::valid($a)
                    && AssignmentInterval::onDate($a, $row->work_date))->values();
                $proofs = $candidates->map(fn ($a) => [
                    'assignment_id' => $a->id, 'bch_id' => $a->source_bch_id, 'bch' => $a->bch_name,
                    'time_in' => $a->time_in, 'time_out' => $a->time_out,
                    'contains_segment' => AssignmentInterval::contains($a, $row, $row->work_date),
                    'canonical_narrowing_proven' => $canonical->canNarrow($row, $a),
                    'narrowing_reason' => $canonical->narrowingReason($row, $a),
                    'canonical_reason' => $canonical->reason($row, $a),
                ])->all();
                $sourceOverlaps = [];
                for ($i = 0; $i < $candidates->count(); $i++) {
                    for ($j = $i + 1; $j < $candidates->count(); $j++) {
                        [$a, $b] = [$candidates[$i], $candidates[$j]];
                        [$start, $end] = AssignmentInterval::segment($a, $row->work_date);
                        [$otherStart, $otherEnd] = AssignmentInterval::segment($b, $row->work_date);
                        if (AssignmentInterval::overlaps($start, $end, $otherStart, $otherEnd)) {
                            $sourceOverlaps[] = [$a->id, $b->id];
                        }
                    }
                }
                $siblings = $dailyRows->get($row->machine_id.'|'.$row->work_date, collect())->where('id', '!=', $row->id);
                $rowOverlaps = $siblings->filter(fn ($r) => AssignmentInterval::overlaps($row->segment_start ?: '', $row->segment_end ?: '',
                    $r->segment_start ?: '', $r->segment_end ?: ''))->pluck('id')->all();
                $target = (object) ['id' => null, 'time_in' => $row->work_date.' '.($row->segment_start ?: '00:00:00'),
                    'time_out' => $row->work_date.' '.($row->segment_end ?: '23:59:59')];
                $normalized = $row->machine_assignment_id === null && $row->project_id === null && $row->command_center_id === null;
                $details[] = [
                    'row' => $this->snapshot($row, $this->rowFields()),
                    'machine' => (array) $machines->get($row->machine_id), 'timeline' => $context,
                    'existing_bch_name' => $bchNames->get($row->command_center_id),
                    'candidates' => $proofs, 'source_overlap_pairs' => $sourceOverlaps,
                    'materialized_overlap_row_ids' => $rowOverlaps,
                    'siblings' => $siblings->map(fn ($r) => $this->snapshot($r, $this->rowFields()))->values()->all(),
                    'relationship_keys_null' => $normalized,
                    'timeline_proves_unassigned' => AssignmentTimelineState::isUnassigned($context['timeline_context']),
                    'unassigned_canonical_reason' => AssignmentTimelineState::isUnassigned($context['timeline_context']) ? $canonical->reason($row, $target) : null,
                    'protected' => ! in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)
                        || $row->status !== 'DRAFT' || $row->reviewed_at !== null || $row->confirmed_at !== null,
                    'disposition' => 'READ_ONLY_REVIEW_REQUIRED; candidate proof is not permission to mutate or split',
                ];
            }

            return [
                'mode' => 'READ_ONLY', 'period' => ['id' => $period->id, 'from' => $period->date_from->toDateString(),
                    'to' => $period->date_to->toDateString(), 'status' => $period->status],
                'missing_focus_row_ids' => array_values(array_diff($focusIds, $rows->pluck('id')->all())),
                'summary' => ['period_rows' => $rows->count(), 'audited_rows' => count($details),
                    'null_assignment_rows' => $rows->whereNull('machine_assignment_id')->count(), 'unassigned_context_counts' => $byContext],
                'rows' => $details,
                'assignments' => $history->map(fn ($a) => $this->snapshot($a, ['id', 'machine_id', 'project_id', 'command_center_id', 'source_bch_id', 'bch_name', 'time_in', 'time_out']))->all(),
                'events' => $events->map(fn ($e) => $this->snapshot($e, ['id', 'machine_id', 'type', 'occurred_at', 'from_project_id', 'to_project_id', 'from_command_center_id', 'to_command_center_id']))->all(),
                'cases' => $cases->map(fn ($c) => $this->snapshot($c, ['id', 'machine_id', 'machine_assignment_id', 'work_date', 'scope_key', 'status', 'source_version', 'pairing_computed_at']))->all(),
                'members' => $members->map(fn ($m) => $this->snapshot($m, ['id', 'daily_photo_case_id', 'ocr_job_id', 'capture_datetime', 'pairing_state', 'assignment_resolution_status']))->all(),
                'intervals' => $intervals->map(fn ($i) => $this->snapshot($i, ['id', 'daily_photo_case_id', 'sequence', 'start_evidence_id', 'end_evidence_id', 'raw_start_at', 'raw_end_at', 'pairing_policy_version']))->all(),
                'jobs' => $jobs->map(function ($j): array {
                    $item = $this->snapshot($j, ['id', 'daily_photo_case_id', 'zalo_attachment_id', 'machine_id', 'document_type', 'status', 'review_status', 'reviewed_at', 'extracted_date', 'extracted_time']);
                    $metadata = json_decode($j->daily_metadata ?? '{}', true);
                    $item['relationship_metadata'] = array_intersect_key($metadata['case_materialization'] ?? [], array_flip(['machine_assignment_id', 'scope_key', 'candidate_machine_assignment_ids', 'assignment_resolution_status']));

                    return $item;
                })->all(),
                'attachments' => $attachments->all(),
                'journals' => $journals->map(fn ($j) => $this->snapshot($j, ['id', 'journal_document_id', 'work_date', 'start_time', 'end_time', 'total_minutes']))->all(),
                'shared_rows' => $sharedRows->map(fn ($r) => $this->snapshot($r, [...$this->rowFields(), 'period_status']))->all(),
                'normalization_audits' => $audits->map(function ($a): array {
                    $properties = json_decode($a->properties, true);

                    return ['id' => $a->id, 'row_id' => $a->subject_id, 'occurred_at' => $a->occurred_at,
                        'relationship_change' => array_intersect_key($properties ?? [], array_flip(['old', 'new', 'timeline_context']))];
                })->all(),
                'validator' => app(ReconciliationExportValidator::class)->validate($period),
                'limits' => ['Current snapshots cannot prove historical preservation without a before snapshot.',
                    'No repair simulation, automatic split, production identity assumption or write is performed.',
                    'Hashes cover stored payload; raw OCR, metadata, notes and credentials are not emitted.'],
            ];
        });
    }

    private function snapshot(object $record, array $fields): array
    {
        return array_intersect_key((array) $record, array_flip($fields))
            + ['snapshot_sha256' => hash('sha256', json_encode($record, JSON_THROW_ON_ERROR))];
    }

    private function rowFields(): array
    {
        $fields = ['id', 'reconciliation_period_id', 'machine_id', 'machine_assignment_id', 'project_id', 'command_center_id',
            'work_date', 'segment_start', 'segment_end', 'status', 'reviewed_at', 'confirmed_at', 'manually_edited_at',
            'evidence_status', 'daily_ocr_job_ids', 'journal_row_ids', 'ai_reconciliation_job_id', 'daily_intervals',
            'ocr_check_in_raw', 'ocr_check_out_raw', 'rounded_check_in', 'rounded_check_out', 'confirmed_check_in', 'confirmed_check_out',
            'gps_check_in', 'gps_check_out', 'regular_minutes', 'lunch_minutes', 'ot_afternoon_minutes', 'ot_evening_minutes'];
        foreach (DailyTimeAllocator::KINDS as $kind) {
            $fields[] = $kind.'_start';
            $fields[] = $kind.'_end';
        }

        return $fields;
    }
}
