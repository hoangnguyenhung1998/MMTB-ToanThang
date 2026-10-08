<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\DB;

/** SELECT-only diagnostic: default redacted, optional safe time/reference details. */
class ReconciliationConsistencyAuditService
{
    public function audit(ReconciliationPeriod $period, ?int $machineId = null, ?string $from = null, ?string $to = null, bool $details = false, ?string $release = null): array
    {
        return DB::transaction(fn () => $this->collect($period, $machineId, $from, $to, $details, $release));
    }

    private function collect(ReconciliationPeriod $period, ?int $machineId, ?string $from, ?string $to, bool $details, ?string $release): array
    {
        $from ??= $period->date_from->toDateString();
        $to ??= $period->date_to->toDateString();
        $rows = DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)
            ->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
            ->whereBetween('work_date', [$from, $to.' 23:59:59'])->orderBy('id')->get();
        foreach ($rows as $row) {
            $row->work_date = substr($row->work_date, 0, 10);
        }
        $ids = $rows->pluck('machine_id')->unique()->all();
        $assignments = DB::table('machine_assignments as a')
            ->leftJoin('machine_assignment_bch_resolutions as r', 'r.machine_assignment_id', '=', 'a.id')
            ->leftJoin('projects as p', 'p.id', '=', 'a.project_id')
            ->leftJoin('command_centers as b', 'b.id', '=', DB::raw('COALESCE(a.command_center_id, r.command_center_id)'))
            ->whereIn('a.machine_id', $ids)->get(['a.*', 'p.id as source_project_id', 'b.id as source_bch_id']);
        $ownership = new DayBasedAssignmentOwnership($assignments, DB::table('machine_events')->whereIn('machine_id', $ids)->get(['id', 'machine_id', 'type', 'occurred_at']));
        $canonical = new CanonicalAssignmentRelinker($ids, $from, $to, false,
            $rows->flatMap(fn ($r) => json_decode($r->daily_ocr_job_ids ?? '[]', true) ?? [])->unique()->all());
        $classifier = new ReconciliationDuplicateClassifier;
        $groups = [];
        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $reasonCounts = [];
        foreach ($rows->groupBy(fn ($r) => $r->machine_id.'|'.$r->work_date) as $siblings) {
            $first = $siblings->first();
            $day = $ownership->resolve($first->machine_id, $first->work_date);
            $details = [];
            foreach ($siblings as $row) {
                $reason = $day['reason'];
                if (! $reason && $day['assignment']) {
                    $reason = $canonical->reason($row, $day['assignment']);
                }
                if ($reason) {
                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                }
                $details[] = ['row_id' => $row->id, 'assignment_id' => $row->machine_assignment_id,
                    'project_id' => $row->project_id, 'bch_id' => $row->command_center_id,
                    'protected' => $classifier->protected($row), 'canonical_reason' => $reason,
                    'canonical_needs_relink' => $day['assignment'] ? $canonical->needsRelink($row, $day['assignment']) : false,
                    'snapshot_sha256' => hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR))];
            }
            $pairs = [];
            $list = $siblings->all();
            for ($i = 0; $i < count($list); $i++) {
                for ($j = $i + 1; $j < count($list); $j++) {
                    $proof = $classifier->compare($list[$i], $list[$j]);
                    if (! $proof['protected'] && ((! $classifier->hasEvidence($list[$i]) && ! $canonical->hasContent($list[$i]))
                        || (! $classifier->hasEvidence($list[$j]) && ! $canonical->hasContent($list[$j])))) {
                        $proof['category'] = 'A';
                    }
                    unset($proof['changes']); // No values, including descriptive/HUMAN text, leave the database.
                    if (! in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)
                        || $canonical->protectedEvidence($list[$i]) || $canonical->protectedEvidence($list[$j])
                        || ! $day['assignment'] || $day['reason'] || $canonical->reason($list[$i], $day['assignment'])
                        || $canonical->reason($list[$j], $day['assignment'])) {
                        $proof['category'] = 'D';
                    }
                    $counts[$proof['category']]++;
                    $pairs[] = ['row_ids' => [$list[$i]->id, $list[$j]->id]] + $proof;
                }
            }
            $groups[] = ['machine_id' => $first->machine_id, 'work_date' => $first->work_date,
                'owner_assignment_id' => $day['assignment']->id ?? null,
                'owner_bch_id' => $day['assignment']->source_bch_id ?? null,
                'ownership_reason' => $day['reason'], 'rows' => $details, 'pairs' => $pairs];
        }

        $validation = app(ReconciliationExportValidator::class)->validate($period, $machineId, $from, $to);
        $classify = function ($messages): array {
            $counts = [];
            foreach ($messages as $message) {
                $reason = 'OTHER_VALIDATION';
                foreach (['CANONICAL_OCR_CONFLICT', 'CANONICAL_CONFLICT', 'DAY_OWNERSHIP_DUPLICATE', 'UNASSIGNED_TIME_CONFLICT',
                    'INVALID_TIMELINE', 'TRUE_ASSIGNMENT_OVERLAP', 'NO_BCH_RESOLUTION', 'NO_PROJECT_RESOLUTION',
                    'LIFECYCLE_ASSIGNMENT_CONFLICT', 'LIFECYCLE_AMBIGUITY',
                    'không còn khớp phân công nguồn' => 'DAY_OWNERSHIP_MISMATCH',
                    'không tìm thấy phân công nguồn' => 'MISSING_SOURCE',
                    'hai BCH có khoảng giờ giống hệt' => 'IDENTICAL_ALLOCATED_TIME',
                    'tổng hành chính' => 'DAILY_REGULAR_LIMIT',
                    'chưa phân bổ giờ' => 'MISSING_ALLOCATION',
                    'chồng lấn' => 'MATERIALIZED_OR_SOURCE_OVERLAP',
                    'chưa xác định BCH' => 'MISSING_BCH', 'chưa xác định dự án' => 'MISSING_PROJECT',
                    'thiếu khoảng giờ' => 'MISSING_SEGMENT'] as $pattern => $label) {
                    if (is_int($pattern)) {
                        $pattern = $label;
                    }
                    if (str_contains($message, $pattern)) {
                        $reason = $label;
                        break;
                    }
                }
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }

            return $counts;
        };

        $report = ['schema_version' => $details ? 2 : 1, 'read_only' => true, 'period_id' => $period->id,
            'scope' => ['machine_id' => $machineId, 'from' => $from, 'to' => $to],
            'summary' => ['rows' => $rows->count(), 'machine_days' => count($groups), 'duplicate_pairs' => $counts, 'canonical_reasons' => $reasonCounts],
            'validation' => ['blocking_messages' => $validation['blocking']->count(), 'blocking_by_reason' => $classify($validation['blocking']),
                'warning_messages' => $validation['warnings']->count(), 'warnings_by_reason' => $classify($validation['warnings'])],
            'groups' => $groups];
        if ($details) {
            $report['operator_reported_release'] = $release;
            $report['evidence'] = app(ReconciliationEvidenceAudit::class)->collect($period, $groups);
        }

        return $report;
    }
}
