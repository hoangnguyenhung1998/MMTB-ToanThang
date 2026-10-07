<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationLinkRepairService
{
    public function repair(ReconciliationPeriod $period, ?int $userId): array
    {
        return DB::transaction(function () use ($period, $userId) {
            $period = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (! in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)) {
                throw new RuntimeException('Kỳ đã chốt hoặc khóa, không thể sửa liên kết.');
            }
            $result = ['repaired' => 0, 'removed' => 0, 'unresolved' => 0];
            $result['diagnostics'] = ['total_inspected' => 0, 'already_correct' => 0, 'repairable_stale_links' => 0,
                'reasons' => [], 'rows' => []];
            // Keep all siblings of each machine together, including stale rows.
            $machineIds = DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)
                ->distinct()->orderBy('machine_id')->pluck('machine_id')->all();
            foreach (array_chunk($machineIds, 100) as $ids) {
                $rows = DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)
                    ->whereIn('machine_id', $ids)->orderBy('id')->lockForUpdate()->get();
                // SQLite legacy fixtures may store a DATE as midnight datetime;
                // normalize once, also preserving MySQL DATE semantics.
                foreach ($rows as $row) {
                    $row->work_date = substr($row->work_date, 0, 10);
                }
                $assignments = DB::table('machine_assignments as a')
                    ->leftJoin('machine_assignment_bch_resolutions as r', 'r.machine_assignment_id', '=', 'a.id')
                    ->leftJoin('projects as p', 'p.id', '=', 'a.project_id')
                    ->leftJoin('command_centers as b', 'b.id', '=', DB::raw('COALESCE(a.command_center_id, r.command_center_id)'))
                    ->whereIn('a.machine_id', $ids)
                    ->where(function ($query) use ($rows) {
                        $query->whereIn('a.id', $rows->pluck('machine_assignment_id')->filter()->unique())
                            ->orWhere(function ($query) use ($rows) {
                                $query->where('a.time_in', '<=', $rows->max('work_date').' 23:59:59')
                                    ->where(fn ($query) => $query->whereNull('a.time_out')
                                        ->orWhere('a.time_out', '>=', $rows->min('work_date').' 00:00:00'));
                            });
                    })
                    ->select(['a.*', 'p.id as source_project_id', 'b.id as source_bch_id'])->lockForUpdate()->get()->keyBy('id');
                // Canonical membership is scoped by assignment, not by reconciliation row.
                // Moving that identity needs a separate canonical migration, outside link repair.
                $canonicalScopes = DB::table('daily_photo_cases as c')->whereIn('c.machine_id', $ids)
                    ->whereBetween('c.work_date', [$rows->min('work_date'), $rows->max('work_date').' 23:59:59'])
                    ->whereExists(fn ($query) => $query->selectRaw('1')->from('daily_photo_case_evidence as e')
                        ->whereColumn('e.daily_photo_case_id', 'c.id'))
                    ->select(['c.machine_id', 'c.work_date', 'c.machine_assignment_id'])->lockForUpdate()->get()
                    ->mapWithKeys(fn ($case) => [$case->machine_id.'|'.substr($case->work_date, 0, 10).'|'.$case->machine_assignment_id => true])->all();
                $byMachine = $assignments->groupBy('machine_id');
                $effective = [];
                $targets = [];
                $duplicates = [];
                foreach ($rows as $row) {
                    $key = $row->machine_id.'|'.$row->work_date;
                    if (! isset($effective[$key])) {
                        $effective[$key] = [];
                        foreach ($byMachine->get($row->machine_id, collect()) as $assignment) {
                            if ($this->onDate($assignment, $row->work_date)) {
                                $effective[$key][] = $assignment;
                            }
                        }
                    }
                    $targets[$key.'|'.$row->machine_assignment_id][$row->id] = true;
                    $source = $assignments->get($row->machine_assignment_id);
                    if ($source && (int) $source->machine_id === (int) $row->machine_id
                        && $this->usable($source) && $this->onDate($source, $row->work_date)
                        && $this->withinSegment($source, $row)
                        && ! $this->ambiguous($source, $row, $effective[$key])) {
                        $duplicates[$this->payloadKey($row)] = true;
                    }
                }
                $updates = [];
                $deletes = [];
                $logs = [];
                $now = now()->toDateTimeString();
                [$orderedRows, $writeLevels] = $this->orderByDependencies($rows->all(), $assignments, $effective, $targets);
                foreach ($orderedRows as $row) {
                    $result['diagnostics']['total_inspected']++;
                    $source = $assignments->get($row->machine_assignment_id);
                    $key = $row->machine_id.'|'.$row->work_date;
                    $exact = $source && (int) $source->machine_id === (int) $row->machine_id
                        && AssignmentInterval::valid($source) && $this->onDate($source, $row->work_date);
                    $protected = $this->protected($row);
                    $human = $row->manually_edited_at !== null;
                    if ($source && ! AssignmentInterval::valid($source)) {
                        $this->unresolved($result, $row, 'INVALID_TIMELINE');

                        continue;
                    }
                    if (($row->segment_start && $row->segment_end && $row->segment_start >= $row->segment_end)
                        || ((! $row->segment_start || ! $row->segment_end) && $this->hasData($row))) {
                        $this->unresolved($result, $row, 'INVALID_SEGMENT');

                        continue;
                    }
                    // A same-date source can also be stale after a time-level transfer.
                    // Empty drafts retain the existing exact-source boundary narrowing rule.
                    $needsTarget = ! $exact || (! $this->withinSegment($source, $row)
                        && ($human || $this->hasData($row) || $this->containedCandidates($row, $effective[$key])));
                    if ($needsTarget) {
                        if ($protected) {
                            $this->unresolved($result, $row, 'PROTECTED_RELATIONSHIP');

                            continue;
                        }
                        if (isset($canonicalScopes[$key.'|'.$row->machine_assignment_id]) || $this->hasCanonicalReference($row)) {
                            $this->unresolved($result, $row, 'CANONICAL_RELATIONSHIP');

                            continue;
                        }
                        $candidates = $this->containedCandidates($row, $effective[$key]);
                        // Legacy empty all-day drafts can be narrowed only to a sole date candidate.
                        if (! $candidates && ! $human && ! $this->hasData($row) && count($effective[$key]) === 1) {
                            $candidates = $effective[$key];
                        }
                        $candidate = count($candidates) === 1 ? $candidates[0] : null;
                        if (! $candidate) {
                            // Preserve the proven full-payload deduplication/expired-empty cleanup.
                            if (! $exact && $source && (int) $source->machine_id === (int) $row->machine_id
                                && ! $this->onDate($source, $row->work_date) && ! $human
                                && ((! $effective[$key] && ! $this->hasData($row)) || isset($duplicates[$this->payloadKey($row)]))) {
                                $deletes[] = $row->id;
                                unset($targets[$key.'|'.$row->machine_assignment_id][$row->id]);
                                $logs[] = $this->log($row, $userId, 'reconciliation.stale_row_removed',
                                    'Xóa dòng nháp nằm ngoài thời gian của phân công nguồn.',
                                    ['row' => array_intersect_key((array) $row, array_flip(['id', 'work_date', 'project_id', 'command_center_id', 'machine_assignment_id']))], $now);
                                $result['removed']++;

                                continue;
                            }
                            $reason = count($candidates) > 1 ? 'TRUE_ASSIGNMENT_OVERLAP'
                                : (! $effective[$key] ? 'NO_EFFECTIVE_ASSIGNMENT' : 'SEGMENT_AMBIGUITY');
                            if (collect($effective[$key])->contains(fn ($a) => ! AssignmentInterval::valid($a))) {
                                $reason = 'INVALID_TIMELINE';
                            }
                            $this->unresolved($result, $row, $reason);

                            continue;
                        }
                        $source = $candidate;
                    }
                    if (! AssignmentInterval::valid($source)) {
                        $this->unresolved($result, $row, 'INVALID_TIMELINE');

                        continue;
                    }
                    if (! $this->usable($source)) {
                        $this->unresolved($result, $row, $source->source_project_id === null ? 'NO_PROJECT_RESOLUTION' : 'NO_BCH_RESOLUTION');

                        continue;
                    }
                    if (collect($effective[$key])->contains(fn ($candidate) => ! AssignmentInterval::valid($candidate))) {
                        $this->unresolved($result, $row, 'INVALID_TIMELINE');

                        continue;
                    }
                    if ($this->ambiguous($source, $row, $effective[$key])) {
                        $this->unresolved($result, $row, 'TRUE_ASSIGNMENT_OVERLAP');

                        continue;
                    }
                    [$start, $end] = $this->segment($source, $row->work_date);
                    $changes = [];
                    foreach (['machine_assignment_id' => $source->id, 'project_id' => $source->source_project_id,
                        'command_center_id' => $source->source_bch_id] as $field => $value) {
                        if ((int) $row->$field !== (int) $value) {
                            $changes[$field] = $value;
                        }
                    }
                    if (! $this->withinSegment($source, $row)) {
                        if ($human || $this->hasData($row)) {
                            $this->unresolved($result, $row, 'SEGMENT_AMBIGUITY');

                            continue;
                        }
                        // Narrow source boundaries only; never expand existing segments.
                        $changes['segment_start'] = max($row->segment_start ?: $start, $start);
                        $changes['segment_end'] = min($row->segment_end ?: $end, $end);
                        if ($changes['segment_start'] >= $changes['segment_end']) {
                            $this->unresolved($result, $row, 'INVALID_SEGMENT');

                            continue;
                        }
                    }
                    if (! $changes) {
                        $result['diagnostics']['already_correct']++;

                        continue;
                    }
                    if ($protected) {
                        $this->unresolved($result, $row, 'PROTECTED_RELATIONSHIP');

                        continue;
                    }
                    if ((int) $row->machine_assignment_id !== (int) $source->id
                        && (isset($canonicalScopes[$key.'|'.$row->machine_assignment_id])
                            || $this->hasCanonicalReference($row))) {
                        $this->unresolved($result, $row, 'CANONICAL_RELATIONSHIP');

                        continue;
                    }
                    $occupants = $targets[$key.'|'.$source->id] ?? [];
                    unset($occupants[$row->id]);
                    if ($occupants) {
                        // An identical donor already preserves every meaningful value.
                        if (! $exact && ! $human && isset($duplicates[$this->payloadKey($row)])) {
                            $deletes[] = $row->id;
                            unset($targets[$key.'|'.$row->machine_assignment_id][$row->id]);
                            $logs[] = $this->log($row, $userId, 'reconciliation.stale_row_removed',
                                'Xóa dòng nháp trùng toàn bộ dữ liệu với dòng nguồn hợp lệ.', ['row_id' => $row->id], $now);
                            $result['removed']++;
                        } else {
                            $this->unresolved($result, $row, 'TARGET_DUPLICATE');
                        }

                        continue;
                    }
                    $old = array_intersect_key((array) $row, $changes);
                    $updates[$row->id] = $changes;
                    unset($targets[$key.'|'.$row->machine_assignment_id][$row->id]);
                    $targets[$key.'|'.$source->id][$row->id] = true;
                    if ((int) $row->machine_assignment_id !== (int) $source->id) {
                        $result['diagnostics']['repairable_stale_links']++;
                    }
                    $logs[] = $this->log($row, $userId, 'reconciliation.links_repaired',
                        'Khôi phục liên kết từ đúng phân công nguồn của dòng đối chiếu.',
                        ['old' => $old, 'new' => $changes, 'machine_assignment_id' => $source->id], $now);
                    $result['repaired']++;
                }
                // Release occupied identities before a dependent row claims them.
                // CASE updates within each wave have no dependency on one another.
                $waves = [];
                foreach ($updates as $id => $changes) {
                    $waves[$writeLevels[$id]][$id] = $changes;
                }
                ksort($waves);
                foreach ($waves as $wave) {
                    $this->writeUpdates($period->id, $wave, $now);
                }
                foreach (array_chunk($deletes, 250) as $chunk) {
                    DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)->whereIn('id', $chunk)->delete();
                }
                foreach (array_chunk($logs, 100) as $chunk) {
                    DB::table('activity_logs')->insert($chunk);
                }
            }

            return $result;
        });
    }

    private function onDate(object $assignment, string $date): bool
    {
        return AssignmentInterval::onDate($assignment, $date);
    }

    private function usable(object $assignment): bool
    {
        return $assignment->source_project_id !== null && $assignment->source_bch_id !== null;
    }

    private function segment(object $assignment, string $date): array
    {
        return AssignmentInterval::segment($assignment, $date);
    }

    private function withinSegment(object $assignment, object $row): bool
    {
        return AssignmentInterval::contains($assignment, $row, $row->work_date);
    }

    private function ambiguous(object $source, object $row, array $candidates): bool
    {
        [$start, $end] = $this->segment($source, $row->work_date);
        $start = max($start, $row->segment_start ?: $start);
        $end = min($end, $row->segment_end ?: $end);
        foreach ($candidates as $candidate) {
            if ((int) $candidate->id === (int) $source->id) {
                continue;
            }
            [$otherStart, $otherEnd] = $this->segment($candidate, $row->work_date);
            if (! AssignmentInterval::valid($candidate) || AssignmentInterval::overlaps($start, $end, $otherStart, $otherEnd)) {
                return true;
            }
        }

        return false;
    }

    private function protected(object $row): bool
    {
        return $row->status !== 'DRAFT' || $row->reviewed_at !== null || $row->confirmed_at !== null;
    }

    private function hasCanonicalReference(object $row): bool
    {
        foreach (json_decode($row->daily_intervals ?? '[]', true) ?? [] as $interval) {
            if (! empty($interval['canonical_interval_id'])) {
                return true;
            }
        }

        return false;
    }

    private function orderByDependencies(array $rows, $assignments, array $effective, array $targets): array
    {
        $indexed = [];
        $waiting = [];
        $dependents = [];
        $levels = [];
        $queue = [];
        foreach ($rows as $row) {
            $indexed[$row->id] = $row;
            $key = $row->machine_id.'|'.$row->work_date;
            $source = $assignments->get($row->machine_assignment_id);
            $exact = $source && (int) $source->machine_id === (int) $row->machine_id
                && AssignmentInterval::valid($source) && $this->onDate($source, $row->work_date);
            $targetId = $row->machine_assignment_id;
            if (! $exact || (! $this->withinSegment($source, $row)
                && ($row->manually_edited_at || $this->hasData($row) || $this->containedCandidates($row, $effective[$key])))) {
                $candidates = $this->containedCandidates($row, $effective[$key]);
                if (! $candidates && ! $row->manually_edited_at && ! $this->hasData($row) && count($effective[$key]) === 1) {
                    $candidates = $effective[$key];
                }
                if (count($candidates) === 1) {
                    $targetId = $candidates[0]->id;
                }
            }
            $dependencies = (int) $targetId !== (int) $row->machine_assignment_id
                ? ($targets[$key.'|'.$targetId] ?? []) : [];
            unset($dependencies[$row->id]);
            $waiting[$row->id] = count($dependencies);
            $levels[$row->id] = 0;
            foreach ($dependencies as $id => $_) {
                $dependents[$id][] = $row->id;
            }
            if (! $dependencies) {
                $queue[] = $row->id;
            }
        }
        $ordered = [];
        for ($head = 0; $head < count($queue); $head++) {
            $id = $queue[$head];
            $ordered[] = $indexed[$id];
            foreach ($dependents[$id] ?? [] as $dependent) {
                $levels[$dependent] = max($levels[$dependent], $levels[$id] + 1);
                if (--$waiting[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
            unset($indexed[$id]);
        }

        // Cycles cannot release an identity safely; occupancy checks fail closed.
        return [array_merge($ordered, array_values($indexed)), $levels];
    }

    private function containedCandidates(object $row, array $candidates): array
    {
        return array_values(array_filter($candidates, fn ($candidate) => $this->withinSegment($candidate, $row)));
    }

    private function unresolved(array &$result, object $row, string $reason): void
    {
        $result['unresolved']++;
        $result['diagnostics']['reasons'][$reason] = ($result['diagnostics']['reasons'][$reason] ?? 0) + 1;
        $result['diagnostics']['rows'][] = ['row_id' => $row->id, 'machine_id' => $row->machine_id,
            'work_date' => $row->work_date, 'reason' => $reason];
    }

    private function hasData(object $row): bool
    {
        $ignored = ['id', 'reconciliation_period_id', 'machine_id', 'machine_assignment_id', 'work_date',
            'project_id', 'command_center_id', 'segment_start', 'segment_end', 'status', 'created_at', 'updated_at',
            'change_type', 'change_note', 'evidence_status'];
        foreach ((array) $row as $field => $value) {
            if (in_array($field, $ignored, true) || $value === null || $value === '' || $value === 0 || $value === '0' || $value === '[]') {
                continue;
            }

            return true;
        }

        return $row->evidence_status !== 'NO_EVIDENCE';
    }

    private function payloadKey(object $row): string
    {
        $payload = (array) $row;
        foreach (['id', 'machine_assignment_id', 'project_id', 'command_center_id', 'created_at', 'updated_at'] as $field) {
            unset($payload[$field]);
        }
        foreach (['daily_ocr_job_ids', 'journal_row_ids'] as $field) {
            $ids = json_decode($payload[$field] ?? '[]', true) ?? [];
            sort($ids);
            $payload[$field] = $ids;
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function log(object $row, ?int $userId, string $event, string $description, array $properties, string $now): array
    {
        return ['user_id' => $userId, 'machine_id' => $row->machine_id, 'subject_type' => (new ReconciliationRow)->getMorphClass(),
            'subject_id' => $row->id, 'event' => $event, 'description' => $description,
            'properties' => json_encode($properties, JSON_THROW_ON_ERROR), 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now];
    }

    private function writeUpdates(int $periodId, array $updates, string $now): void
    {
        // No row/audit observer or mutator is registered. Keep the explicit domain
        // audit in the same transaction without per-row saves.
        $grammar = DB::connection()->getQueryGrammar();
        foreach (array_chunk($updates, 50, true) as $chunk) {
            $columns = array_unique(array_merge(...array_map('array_keys', $chunk)));
            $sets = [];
            $bindings = [];
            foreach ($columns as $column) {
                $wrapped = $grammar->wrap($column);
                $case = "$wrapped = CASE ".$grammar->wrap('id');
                foreach ($chunk as $id => $changes) {
                    if (array_key_exists($column, $changes)) {
                        $case .= ' WHEN ? THEN ?';
                        array_push($bindings, $id, $changes[$column]);
                    }
                }
                $sets[] = $case." ELSE $wrapped END";
            }
            $sets[] = $grammar->wrap('updated_at').' = ?';
            $bindings[] = $now;
            $bindings[] = $periodId;
            array_push($bindings, ...array_keys($chunk));
            DB::update('UPDATE '.$grammar->wrapTable('reconciliation_rows').' SET '.implode(', ', $sets)
                .' WHERE '.$grammar->wrap('reconciliation_period_id').' = ? AND '.$grammar->wrap('id')
                .' IN ('.implode(',', array_fill(0, count($chunk), '?')).')', $bindings);
        }
    }
}
