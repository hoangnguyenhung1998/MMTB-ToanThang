<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationLinkRepairService
{
    public function repair(ReconciliationPeriod $period, ?int $userId, ?int $machineId = null, ?string $from = null, ?string $to = null): array
    {
        return DB::transaction(function () use ($period, $userId, $machineId, $from, $to) {
            $period = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (! in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)) {
                throw new RuntimeException('Kỳ đã chốt hoặc khóa, không thể sửa liên kết.');
            }
            $result = ['repaired' => 0, 'normalized_unassigned' => 0, 'removed' => 0, 'unresolved' => 0];
            $result['diagnostics'] = ['total_inspected' => 0, 'already_correct' => 0, 'repairable_stale_links' => 0,
                'unassigned_by_context' => [], 'cleaned_by_context' => [], 'reasons' => [], 'rows' => []];
            // Keep all siblings of each machine together, including stale rows.
            $machineIds = DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)
                ->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
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
                    ->select(['a.*', 'p.id as source_project_id', 'b.id as source_bch_id'])->lockForUpdate()->get()->keyBy('id');
                $canonical = new CanonicalAssignmentRelinker($ids, $rows->min('work_date'), $rows->max('work_date'));
                $firstDate = $rows->min('work_date');
                $lastDate = $rows->max('work_date');
                $byMachine = $assignments->filter(fn ($a) => AssignmentInterval::valid($a) && (string) $a->time_in <= $lastDate.' 23:59:59'
                    && (! $a->time_out || (string) $a->time_out >= $firstDate.' 00:00:00'))->groupBy('machine_id');
                $timeline = new AssignmentTimelineState($assignments, DB::table('machine_events')
                    ->whereIn('machine_id', $ids)->whereIn('type', ['RETURN', 'HANDOVER', 'TRANSFER'])
                    ->lockForUpdate()->get(['id', 'machine_id', 'type', 'occurred_at']));
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
                $rowIndex = $rows->keyBy('id');
                [$orderedRows, $writeLevels] = $this->orderByDependencies($rows->all(), $assignments, $effective, $targets, $canonical);
                foreach ($orderedRows as $row) {
                    if (isset($deletes[$row->id]) || ($from && $row->work_date < substr($from, 0, 10)) || ($to && $row->work_date > substr($to, 0, 10))
                        || ($from && strlen($from) > 10 && $row->segment_end && $row->work_date.' '.$row->segment_end <= $from)
                        || ($to && strlen($to) > 10 && $row->segment_start && $row->work_date.' '.$row->segment_start >= $to)) {
                        continue;
                    }
                    $result['diagnostics']['total_inspected']++;
                    $source = $assignments->get($row->machine_assignment_id);
                    $key = $row->machine_id.'|'.$row->work_date;
                    $exact = $source && (int) $source->machine_id === (int) $row->machine_id
                        && AssignmentInterval::valid($source) && $this->onDate($source, $row->work_date);
                    $protected = $this->protected($row);
                    $human = $row->manually_edited_at !== null;
                    if (($row->segment_start && $row->segment_end && $row->segment_start >= $row->segment_end)
                        || ((! $row->segment_start || ! $row->segment_end) && $this->hasData($row))) {
                        $this->unresolved($result, $row, 'INVALID_SEGMENT');

                        continue;
                    }
                    $context = $timeline->context((int) $row->machine_id,
                        $row->work_date.' '.($row->segment_start ?: '00:00:00'),
                        $row->work_date.' '.($row->segment_end ?: '23:59:59'));
                    $context['source_assignment_id'] = $row->machine_assignment_id;
                    $context['_candidate_assignments'] = $effective[$key];
                    $state = $context['timeline_context'];
                    if (AssignmentTimelineState::isUnassigned($state)) {
                        $rich = $human || $this->hasData($row) || $canonical->hasContent($row) || $this->hasCanonicalReference($row);
                        if ($row->machine_assignment_id === null && $row->project_id === null && $row->command_center_id === null) {
                            $target = (object) ['id' => null, 'time_in' => $row->work_date.' '.($row->segment_start ?: '00:00:00'),
                                'time_out' => $row->work_date.' '.($row->segment_end ?: '23:59:59')];
                            $occupants = $targets[$key.'|'] ?? [];
                            unset($occupants[$row->id]);
                            if ($occupants) {
                                $this->unresolved($result, $row, 'UNASSIGNED_IDENTITY_CONFLICT', $context);
                            } elseif ($reason = $canonical->reason($row, $target)) {
                                $this->unresolved($result, $row, $reason, $context);
                            } else {
                                $result['diagnostics']['already_correct']++;
                            }
                        } elseif ($protected) {
                            $this->unresolved($result, $row, 'PROTECTED_RELATIONSHIP', $context);
                        } elseif (! $rich && $source && (int) $source->machine_id === (int) $row->machine_id) {
                            $deletes[$row->id] = $row->id;
                            unset($targets[$key.'|'.$row->machine_assignment_id][$row->id]);
                            $logs[] = $this->log($row, $userId, 'reconciliation.stale_row_removed',
                                'Dọn nháp rỗng ngoài lịch hiệu lực; không lấp khoảng không BCH.',
                                ['row' => (array) $row, 'timeline_context' => $state], $now);
                            $result['removed']++;
                            $result['diagnostics']['cleaned_by_context'][$state] = ($result['diagnostics']['cleaned_by_context'][$state] ?? 0) + 1;
                        } else {
                            // Nullable existing relationships represent proven unassigned ranges.
                            // Canonical validation also proves captures/intervals fit this exact range.
                            $target = (object) ['id' => null, 'time_in' => $row->work_date.' '.($row->segment_start ?: '00:00:00'),
                                'time_out' => $row->work_date.' '.($row->segment_end ?: '23:59:59')];
                            $reason = $canonical->reason($row, $target);
                            $occupants = $targets[$key.'|'] ?? [];
                            unset($occupants[$row->id]);
                            if ($reason || $occupants) {
                                $this->unresolved($result, $row, $reason ?: 'UNASSIGNED_IDENTITY_CONFLICT', $context);

                                continue;
                            }
                            $canonical->plan($row, $target, $userId, $now);
                            $changes = ['machine_assignment_id' => null, 'project_id' => null, 'command_center_id' => null];
                            $updates[$row->id] = $changes;
                            unset($targets[$key.'|'.$row->machine_assignment_id][$row->id]);
                            $targets[$key.'|'][$row->id] = true;
                            $logs[] = $this->log($row, $userId, 'reconciliation.relationship_unassigned',
                                'Chuẩn hóa Không BCH; giữ nguyên dữ liệu nghiệp vụ và identity.',
                                ['old' => array_intersect_key((array) $row, $changes), 'new' => $changes, 'timeline_context' => $state], $now);
                            $result['normalized_unassigned']++;
                            $result['diagnostics']['unassigned_by_context'][$state] = ($result['diagnostics']['unassigned_by_context'][$state] ?? 0) + 1;
                        }

                        continue;
                    }
                    if (in_array($state, ['INVALID_TIMELINE', 'LIFECYCLE_AMBIGUITY', 'LIFECYCLE_ASSIGNMENT_CONFLICT'], true)) {
                        $this->unresolved($result, $row, $state, $context);

                        continue;
                    }
                    // A same-date source can also be stale after a time-level transfer.
                    // Empty drafts retain the existing exact-source boundary narrowing rule.
                    $needsTarget = ! $exact || (! $this->withinSegment($source, $row)
                        && ($human || $this->hasData($row) || $canonical->hasContent($row) || $this->containedCandidates($row, $effective[$key])));
                    if ($needsTarget) {
                        if ($protected) {
                            $this->unresolved($result, $row, 'PROTECTED_RELATIONSHIP');

                            continue;
                        }
                        $candidates = $this->containedCandidates($row, $effective[$key]);
                        if (! $candidates && $canonical->hasContent($row)) {
                            $candidates = array_values(array_filter($effective[$key], fn ($a) => $canonical->canNarrow($row, $a)));
                        }
                        // Legacy empty all-day drafts can be narrowed only to a sole date candidate.
                        if (! $candidates && ! $human && ! $this->hasData($row) && ! $canonical->hasContent($row) && count($effective[$key]) === 1) {
                            $candidates = $effective[$key];
                        }
                        $candidate = count($candidates) === 1 ? $candidates[0] : null;
                        if (! $candidate) {
                            // Preserve the proven full-payload deduplication/expired-empty cleanup.
                            if (! $exact && $source && (int) $source->machine_id === (int) $row->machine_id
                                && ! $this->onDate($source, $row->work_date) && ! $human && ! $canonical->hasContent($row) && ! $this->hasCanonicalReference($row)
                                && ((! $effective[$key] && ! $this->hasData($row)) || isset($duplicates[$this->payloadKey($row)]))) {
                                $deletes[$row->id] = $row->id;
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
                            $this->unresolved($result, $row, $reason, $context);

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
                        $this->unresolved($result, $row, 'TRUE_ASSIGNMENT_OVERLAP', $context);

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
                        if (($human || $this->hasData($row) || $canonical->hasContent($row)) && ! $canonical->canNarrow($row, $source)) {
                            $this->unresolved($result, $row, 'SEGMENT_AMBIGUITY', $context);

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
                    $canonicalReason = (int) $row->machine_assignment_id !== (int) $source->id ? $canonical->reason($row, $source) : null;
                    if ($canonicalReason) {
                        $this->unresolved($result, $row, $canonicalReason);

                        continue;
                    }
                    $occupants = $targets[$key.'|'.$source->id] ?? [];
                    unset($occupants[$row->id]);
                    if ($occupants) {
                        $targetRow = count($occupants) === 1 ? $rowIndex->get(array_key_first($occupants)) : null;
                        $sourceEmpty = ! $human && ! $this->hasData($row) && ! $canonical->hasContent($row);
                        $targetEmpty = $targetRow && ! $this->protected($targetRow) && ! $targetRow->manually_edited_at
                            && ! $this->hasData($targetRow) && ! $canonical->hasContent($targetRow);
                        if ($targetRow && $this->protected($targetRow)) {
                            $this->unresolved($result, $row, 'PROTECTED_DUPLICATE');

                            continue;
                        }
                        if ($targetEmpty && ! $sourceEmpty) {
                            $deletes[$targetRow->id] = $targetRow->id;
                            $logs[] = $this->mergeLog($row, $targetRow, $row, $source, $userId, 'EMPTY_TARGET', $now);
                            unset($targets[$key.'|'.$source->id][$targetRow->id]);
                            $result['removed']++;
                            $result['diagnostics']['total_inspected']++;
                        } elseif ($targetRow && ($sourceEmpty || $this->payloadKey($row) === $this->payloadKey($targetRow))) {
                            $canonical->plan($row, $source, $userId, $now);
                            $deletes[$row->id] = $row->id;
                            unset($targets[$key.'|'.$row->machine_assignment_id][$row->id]);
                            $logs[] = $this->mergeLog($row, $targetRow, $targetRow, $source, $userId, $sourceEmpty ? 'EMPTY_SOURCE' : 'IDENTICAL_PAYLOAD', $now);
                            $result['removed']++;

                            continue;
                        } else {
                            $this->unresolved($result, $row, $targetRow ? 'DUPLICATE_PAYLOAD_CONFLICT' : 'TARGET_DUPLICATE');

                            continue;
                        }
                    }
                    $canonical->plan($row, $source, $userId, $now);
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
                // Delete proven empty/identical duplicates before claiming their unique identity.
                foreach (array_chunk(array_values($deletes), 250) as $chunk) {
                    DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)->whereIn('id', $chunk)->delete();
                }
                $canonical->flush($now);
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

    private function orderByDependencies(array $rows, $assignments, array $effective, array $targets, CanonicalAssignmentRelinker $canonical): array
    {
        $indexed = [];
        $waiting = [];
        $dependents = [];
        $levels = [];
        $queue = [];
        $rich = [];
        $empty = [];
        foreach ($rows as $row) {
            $rich[$row->id] = $this->hasData($row) || $canonical->hasContent($row);
            $empty[$row->id] = ! $rich[$row->id] && ! $this->protected($row) && ! $row->manually_edited_at;
        }
        usort($rows, fn ($a, $b) => ($rich[$b->id] <=> $rich[$a->id]) ?: ($a->id <=> $b->id));
        foreach ($rows as $row) {
            $indexed[$row->id] = $row;
            $key = $row->machine_id.'|'.$row->work_date;
            $source = $assignments->get($row->machine_assignment_id);
            $exact = $source && (int) $source->machine_id === (int) $row->machine_id
                && AssignmentInterval::valid($source) && $this->onDate($source, $row->work_date);
            $targetId = $row->machine_assignment_id;
            if (! $exact || (! $this->withinSegment($source, $row)
                && ($row->manually_edited_at || $rich[$row->id] || $this->containedCandidates($row, $effective[$key])))) {
                $candidates = $this->containedCandidates($row, $effective[$key]);
                if (! $candidates && $canonical->hasContent($row)) {
                    $candidates = array_values(array_filter($effective[$key], fn ($a) => $canonical->canNarrow($row, $a)));
                }
                if (! $candidates && ! $row->manually_edited_at && ! $rich[$row->id] && count($effective[$key]) === 1) {
                    $candidates = $effective[$key];
                }
                if (count($candidates) === 1) {
                    $targetId = $candidates[0]->id;
                }
            }
            $dependencies = (int) $targetId !== (int) $row->machine_assignment_id
                ? ($targets[$key.'|'.$targetId] ?? []) : [];
            unset($dependencies[$row->id]);
            // A rich source can remove an empty draft target, so it need not wait for it.
            if ($rich[$row->id]) {
                $dependencies = array_filter($dependencies, fn ($_, $id) => ! $empty[$id], ARRAY_FILTER_USE_BOTH);
            }
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

    private function unresolved(array &$result, object $row, string $reason, array $context = []): void
    {
        if (isset($context['_candidate_assignments'])) {
            $context['effective_assignments'] = array_map(fn ($a) => ['id' => $a->id, 'time_in' => $a->time_in, 'time_out' => $a->time_out], $context['_candidate_assignments']);
            unset($context['_candidate_assignments']);
        }
        $result['unresolved']++;
        $result['diagnostics']['reasons'][$reason] = ($result['diagnostics']['reasons'][$reason] ?? 0) + 1;
        $result['diagnostics']['rows'][] = ['row_id' => $row->id, 'machine_id' => $row->machine_id,
            'work_date' => $row->work_date, 'reason' => $reason] + $context;
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

    private function mergeLog(object $sourceRow, object $targetRow, object $survivor, object $assignment, ?int $actor, string $reason, string $now): array
    {
        return $this->log($survivor, $actor, $survivor->id === $targetRow->id ? 'reconciliation.stale_row_removed' : 'reconciliation.rows_merged',
            'Dọn duplicate an toàn; giữ identity chứa dữ liệu và lịch sử trước merge.',
            ['action' => $reason, 'source' => (array) $sourceRow, 'target' => (array) $targetRow,
                'survivor_row_id' => $survivor->id, 'target_assignment_id' => $assignment->id,
                'target_command_center_id' => $assignment->source_bch_id, 'work_date' => $sourceRow->work_date], $now);
    }

    private function writeUpdates(int $periodId, array $updates, string $now): void
    {
        RelationshipBatchWriter::update('reconciliation_rows', $updates, $now, ['reconciliation_period_id' => $periodId]);
    }
}
