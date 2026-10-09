<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationLinkRepairService
{
    private const SNAPSHOT_POLICY = 'TRANSFER_OWNERSHIP_CANONICAL_V2';

    public function repair(ReconciliationPeriod $period, ?int $userId, ?int $machineId = null, ?string $from = null, ?string $to = null, ?string $runId = null): array
    {
        return $this->run($period, $userId, $machineId, $from, $to, true, true, $runId);
    }

    /** SELECT-only simulation of the same decisions; never calls flush or a writer. */
    public function plan(ReconciliationPeriod $period, ?int $machineId = null, ?string $from = null, ?string $to = null): array
    {
        return $this->run($period, null, $machineId, $from, $to, false);
    }

    public function repairExpected(ReconciliationPeriod $period, int $actor, string $expected, string $runId): array
    {
        return DB::transaction(function () use ($period, $actor, $expected, $runId) {
            $plan = $this->run($period, null, null, null, null, false, true);
            if (! hash_equals($expected, $plan['snapshot_fingerprint'])) {
                throw new \DomainException('STALE_PREVIEW');
            }

            return $this->repair($period, $actor, runId: $runId);
        });
    }

    private function run(ReconciliationPeriod $period, ?int $userId, ?int $machineId, ?string $from, ?string $to, bool $apply, bool $lock = false, ?string $runId = null): array
    {
        return DB::transaction(function () use ($period, $userId, $machineId, $from, $to, $apply, $lock, $runId) {
            $period = ReconciliationPeriod::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($period->id);
            if (! in_array($period->status, ['DRAFT', 'GENERATED', 'REVIEWING'], true)) {
                throw new RuntimeException('Kỳ đã chốt hoặc khóa, không thể sửa liên kết.');
            }
            $result = ['repaired' => 0, 'normalized_unassigned' => 0, 'removed' => 0, 'unresolved' => 0];
            $result['duplicates_consolidated'] = 0;
            $result['actions'] = [];
            $fingerprint = hash_init('sha256');
            // A preview approved under PR67 must not authorize the expanded ownership policy.
            hash_update($fingerprint, self::SNAPSHOT_POLICY);
            hash_update($fingerprint, serialize(ReconciliationRepairSnapshot::normalize([$period->getAttributes(), $machineId, $from, $to])));
            $result['diagnostics'] = ['total_inspected' => 0, 'already_correct' => 0, 'repairable_stale_links' => 0,
                'unassigned_by_context' => [], 'cleaned_by_context' => [], 'reasons' => [], 'rows' => []];
            // Keep all siblings of each machine together, including stale rows.
            $machineIds = DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)
                ->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
                ->distinct()->orderBy('machine_id')->pluck('machine_id')->all();
            foreach (array_chunk($machineIds, 100) as $ids) {
                $rows = DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)
                    ->whereIn('machine_id', $ids)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
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
                    ->select(['a.*', 'p.id as source_project_id', 'b.id as source_bch_id'])->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
                $canonical = new CanonicalAssignmentRelinker($ids, $rows->min('work_date'), $rows->max('work_date'), $lock,
                    $rows->flatMap(fn ($r) => json_decode($r->daily_ocr_job_ids ?? '[]', true) ?? [])->unique()->all(), ! $apply);
                $firstDate = $rows->min('work_date');
                $lastDate = $rows->max('work_date');
                $events = DB::table('machine_events')
                    ->whereIn('machine_id', $ids)->whereIn('type', ['RETURN', 'HANDOVER', 'TRANSFER'])
                    ->when($lock, fn ($q) => $q->lockForUpdate())->get(['id', 'machine_id', 'type', 'occurred_at']);
                hash_update($fingerprint, serialize(ReconciliationRepairSnapshot::normalize([$rows->all(), $assignments->all(), $events->all(), $canonical->snapshotFingerprint()])));
                $ownership = new DayBasedAssignmentOwnership($assignments, $events);
                $contexts = [];
                $effective = [];
                $targets = [];
                $duplicates = [];
                foreach ($rows as $row) {
                    $key = $row->machine_id.'|'.$row->work_date;
                    if (! isset($effective[$key])) {
                        $day = $ownership->resolve((int) $row->machine_id, $row->work_date);
                        $contexts[$key] = $day['context'];
                        $effective[$key] = $day['assignment'] ? [$day['assignment']] : [];
                    }
                    $targets[$key.'|'.$row->machine_assignment_id][$row->id] = true;
                    $source = $this->sourceForDay($row, $assignments, $effective);
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
                $inspected = [];
                $alreadyCorrectRows = [];
                [$orderedRows, $writeLevels] = $this->orderByDependencies($rows->all(), $assignments, $effective, $targets, $canonical);
                foreach ($orderedRows as $row) {
                    if (isset($deletes[$row->id]) || ($from && $row->work_date < substr($from, 0, 10)) || ($to && $row->work_date > substr($to, 0, 10))) {
                        continue;
                    }
                    $result['diagnostics']['total_inspected']++;
                    $inspected[$row->id] = true;
                    $source = $this->sourceForDay($row, $assignments, $effective);
                    $key = $row->machine_id.'|'.$row->work_date;
                    $exact = $source && (int) $source->machine_id === (int) $row->machine_id
                        && AssignmentInterval::valid($source) && $this->onDate($source, $row->work_date)
                        && count($effective[$key]) === 1 && (int) $source->id === (int) $effective[$key][0]->id;
                    $protected = $this->protected($row);
                    $human = $row->manually_edited_at !== null;
                    if (($row->segment_start && $row->segment_end && $row->segment_start >= $row->segment_end)
                        || ((! $row->segment_start || ! $row->segment_end) && $this->hasData($row))) {
                        $this->unresolved($result, $row, 'INVALID_SEGMENT');

                        continue;
                    }
                    $context = $contexts[$key];
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
                                $alreadyCorrectRows[$row->id] = true;
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
                            $updates[$row->id] = array_replace($updates[$row->id] ?? [], $changes);
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
                    if (in_array($state, ['INVALID_TIMELINE', 'LIFECYCLE_AMBIGUITY', 'LIFECYCLE_ASSIGNMENT_CONFLICT', 'TRUE_ASSIGNMENT_OVERLAP', 'NO_BCH_RESOLUTION', 'NO_PROJECT_RESOLUTION'], true)) {
                        $this->unresolved($result, $row, $state, $context);

                        continue;
                    }
                    // A same-date source can also be stale after a time-level transfer.
                    // Daily ownership is selected before legacy segment and payload checks.
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
                        // Reject malformed legacy ranges before applying the whole-day segment.
                        $changes['segment_start'] = max($row->segment_start ?: $start, $start);
                        $changes['segment_end'] = min($row->segment_end ?: $end, $end);
                        if ($changes['segment_start'] >= $changes['segment_end']) {
                            $this->unresolved($result, $row, 'INVALID_SEGMENT');

                            continue;
                        }
                    }
                    // Ownership is a whole business day, regardless of physical transfer/return time.
                    if ($row->segment_start !== '00:00:00' || $row->segment_end !== '23:59:59') {
                        $changes['segment_start'] = '00:00:00';
                        $changes['segment_end'] = '23:59:59';
                    }
                    if ($reason = $canonical->reason($row, $source)) {
                        $this->unresolved($result, $row, $reason);

                        continue;
                    }
                    if (! $changes) {
                        if ($canonical->needsRelink($row, $source)) {
                            if ($protected) {
                                $this->unresolved($result, $row, 'PROTECTED_RELATIONSHIP');

                                continue;
                            }
                            $canonical->plan($row, $source, $userId, $now);
                            $result['repaired']++;
                        } else {
                            $result['diagnostics']['already_correct']++;
                            $alreadyCorrectRows[$row->id] = true;
                        }

                        continue;
                    }
                    if ($protected) {
                        $this->unresolved($result, $row, 'PROTECTED_RELATIONSHIP');

                        continue;
                    }
                    $occupants = $targets[$key.'|'.$source->id] ?? [];
                    unset($occupants[$row->id]);
                    if ($occupants) {
                        $targetRow = count($occupants) === 1 ? $rowIndex->get(array_key_first($occupants)) : null;
                        $sourceEmpty = ! $human && ! $this->hasData($row) && ! $canonical->hasContent($row);
                        $targetEmpty = $targetRow && ! $this->protected($targetRow) && ! $targetRow->manually_edited_at
                            && ! $this->hasData($targetRow) && ! $canonical->hasContent($targetRow);
                        if ($targetRow && ($this->protected($targetRow) || $canonical->protectedEvidence($row) || $canonical->protectedEvidence($targetRow))) {
                            $this->unresolved($result, $row, 'PROTECTED_DUPLICATE');

                            continue;
                        }
                        $classifier = new ReconciliationDuplicateClassifier;
                        $sourceCarry = $targetRow ? $classifier->ownershipShadowChanges($row, $targetRow) : null;
                        $targetCarry = $targetRow ? $classifier->ownershipShadowChanges($targetRow, $row) : null;
                        $sourceRich = $sourceCarry !== null && $canonical->provesOwnershipSource($row, $targetRow, $source, $sourceCarry);
                        $targetRich = $targetCarry !== null && $canonical->provesOwnershipSource($targetRow, $row, $source, $targetCarry);
                        // Prefer an evidence superset; equal independent bundles have no proven survivor.
                        if ($sourceRich && $targetRich) {
                            $sourceParts = count(json_decode($row->daily_intervals ?? '[]', true) ?? []);
                            $targetParts = count(json_decode($targetRow->daily_intervals ?? '[]', true) ?? []);
                            $sourceRich = $sourceParts > $targetParts;
                            $targetRich = $targetParts > $sourceParts;
                        }
                        if ($sourceRich || $targetRich) {
                            $survivor = $sourceRich ? $row : $targetRow;
                            $redundant = $sourceRich ? $targetRow : $row;
                            $carry = $sourceRich ? $sourceCarry : $targetCarry;
                            $survivorBefore = clone $survivor;
                            $canonical->plan($row, $source, $userId, $now);
                            if ($carry) {
                                $updates[$survivor->id] = array_replace($updates[$survivor->id] ?? [], $carry);
                            }
                            foreach ($carry as $field => $value) {
                                $survivor->$field = $value;
                            }
                            $deletes[$redundant->id] = $redundant->id;
                            unset($targets[$key.'|'.$redundant->machine_assignment_id][$redundant->id]);
                            $logs[] = $this->mergeLog($sourceRich ? $survivorBefore : $row, $sourceRich ? $targetRow : $survivorBefore, $survivor, $source, $userId, 'SAME_OCR_REDUNDANT_SHADOW', $now, $survivorBefore, $carry);
                            $result['removed']++;
                            if ($targetRich) {
                                continue;
                            }
                            if (! isset($inspected[$targetRow->id])) {
                                $result['diagnostics']['total_inspected']++;
                            } elseif (isset($alreadyCorrectRows[$targetRow->id])) {
                                $result['diagnostics']['already_correct']--;
                            }
                        } elseif ($targetEmpty) {
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
                        } elseif ($targetRow && ($proof = (new ReconciliationDuplicateClassifier)->compare($row, $targetRow))['category'] === 'C') {
                            if ($reason = $canonical->reason($targetRow, $source)) {
                                $this->unresolved($result, $row, $reason);

                                continue;
                            }
                            $canonical->plan($row, $source, $userId, $now);
                            $targetBefore = clone $targetRow;
                            $updates[$targetRow->id] = array_replace($updates[$targetRow->id] ?? [], $proof['changes']);
                            foreach ($proof['changes'] as $field => $value) {
                                $targetRow->$field = $value;
                            }
                            $deletes[$row->id] = $row->id;
                            unset($targets[$key.'|'.$row->machine_assignment_id][$row->id]);
                            $logs[] = $this->mergeLog($row, $targetBefore, $targetRow, $source, $userId, 'COMPLEMENTARY_DESCRIPTORS', $now);
                            $result['removed']++;

                            continue;
                        } else {
                            $this->unresolved($result, $row, $targetRow ? 'DUPLICATE_PAYLOAD_CONFLICT' : 'TARGET_DUPLICATE', $targetRow ? [
                                'conflicting_fields' => $classifier->compare($row, $targetRow)['conflicting_fields'],
                                'missing_proof' => 'Single canonical source, consistent interval union and an authoritative survivor time bundle are required.',
                            ] : []);

                            continue;
                        }
                    }
                    $canonical->plan($row, $source, $userId, $now);
                    $old = array_intersect_key((array) $row, $changes);
                    $updates[$row->id] = array_replace($updates[$row->id] ?? [], $changes);
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
                foreach ($logs as $log) {
                    $properties = json_decode($log['properties'], true, 512, JSON_THROW_ON_ERROR);
                    if (isset($properties['survivor_row_id'])) {
                        $result['duplicates_consolidated']++;
                        $result['actions'][] = ['row_ids' => [$properties['source']['id'], $properties['target']['id']],
                            'survivor_row_id' => $properties['survivor_row_id'], 'reason' => $properties['action']];
                    }
                }
                if (! $apply) {
                    continue;
                }
                // Delete proven empty/identical duplicates before claiming their unique identity.
                foreach (array_chunk(array_values($deletes), 250) as $chunk) {
                    DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)->whereIn('id', $chunk)->delete();
                }
                $canonical->flush($now, $runId);
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
                    if ($runId !== null) {
                        foreach ($chunk as &$log) {
                            $properties = json_decode($log['properties'], true, 512, JSON_THROW_ON_ERROR);
                            $log['properties'] = json_encode($properties + ['repair_run_id' => $runId], JSON_THROW_ON_ERROR);
                        }
                        unset($log);
                    }
                    DB::table('activity_logs')->insert($chunk);
                }
            }

            $result['snapshot_fingerprint'] = hash_final($fingerprint);
            $result['protected'] = collect($result['diagnostics']['reasons'])->filter(fn ($count, $reason) => str_starts_with($reason, 'PROTECTED_'))->sum();

            return $result;
        });
    }

    /** Local/restored-copy dry run: exercise actual guarded writes inside a rolled-back savepoint. */
    public function preview(ReconciliationPeriod $period, ?int $machineId = null, ?string $from = null, ?string $to = null): array
    {
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $count = fn () => DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)
                ->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
                ->when($from, fn ($q) => $q->whereDate('work_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('work_date', '<=', $to))->count();
            $before = $count();
            $result = $this->repair($period, null, $machineId, $from, $to);
            $after = $count();

            return ['dry_run' => true, 'period_id' => $period->id, 'before_rows' => $before, 'after_rows' => $after, 'repair' => $result];
        } finally {
            DB::rollBack($level);
        }
    }

    private function sourceForDay(object $row, $assignments, array $effective): ?object
    {
        $owner = $effective[$row->machine_id.'|'.$row->work_date][0] ?? null;

        return $owner && (int) $owner->id === (int) $row->machine_assignment_id ? $owner : $assignments->get($row->machine_assignment_id);
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
        return (new ReconciliationDuplicateClassifier)->protected($row);
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
            $source = $this->sourceForDay($row, $assignments, $effective);
            $exact = $source && (int) $source->machine_id === (int) $row->machine_id
                && AssignmentInterval::valid($source) && $this->onDate($source, $row->work_date)
                        && count($effective[$key]) === 1 && (int) $source->id === (int) $effective[$key][0]->id;
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
        return (new ReconciliationDuplicateClassifier)->hasEvidence($row);
    }

    private function payloadKey(object $row): string
    {
        return (new ReconciliationDuplicateClassifier)->key($row);
    }

    private function log(object $row, ?int $userId, string $event, string $description, array $properties, string $now): array
    {
        return ['user_id' => $userId, 'machine_id' => $row->machine_id, 'subject_type' => (new ReconciliationRow)->getMorphClass(),
            'subject_id' => $row->id, 'event' => $event, 'description' => $description,
            'properties' => json_encode($properties, JSON_THROW_ON_ERROR), 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now];
    }

    private function mergeLog(object $sourceRow, object $targetRow, object $survivor, object $assignment, ?int $actor, string $reason, string $now, ?object $survivorBefore = null, array $carried = []): array
    {
        $proof = [];
        if ($reason === 'SAME_OCR_REDUNDANT_SHADOW') {
            $proof = ['proof' => ['same_ocr_job_ids' => json_decode($survivor->daily_ocr_job_ids, true, 512, JSON_THROW_ON_ERROR),
                'canonical_interval_ids' => array_column(json_decode($survivor->daily_intervals, true, 512, JSON_THROW_ON_ERROR), 'canonical_interval_id'),
                'preserve_whole_row' => $carried === [], 'carried_fields' => array_keys($carried), 'ownership_contract' => 'CANONICAL_SOURCE_WITH_UNPAIRED_CAPTURES', 'owner_assignment_id' => $assignment->id],
                'survivor_before' => (array) ($survivorBefore ?? $survivor),
                'survivor_after' => $survivor->id === $sourceRow->id ? array_replace((array) $survivor,
                    ['machine_assignment_id' => $assignment->id, 'project_id' => $assignment->source_project_id,
                        'command_center_id' => $assignment->source_bch_id, 'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'updated_at' => $now]) : array_replace((array) $survivor, $carried ? ['updated_at' => $now] : [])];
        }

        return $this->log($survivor, $actor, $survivor->id === $targetRow->id ? 'reconciliation.stale_row_removed' : 'reconciliation.rows_merged',
            'Dọn duplicate an toàn; giữ identity chứa dữ liệu và lịch sử trước merge.',
            ['action' => $reason, 'source' => (array) $sourceRow, 'target' => (array) $targetRow,
                'survivor_row_id' => $survivor->id, 'target_assignment_id' => $assignment->id,
                'target_command_center_id' => $assignment->source_bch_id, 'work_date' => $sourceRow->work_date] + $proof, $now);
    }

    private function writeUpdates(int $periodId, array $updates, string $now): void
    {
        RelationshipBatchWriter::update('reconciliation_rows', $updates, $now, ['reconciliation_period_id' => $periodId]);
    }
}
