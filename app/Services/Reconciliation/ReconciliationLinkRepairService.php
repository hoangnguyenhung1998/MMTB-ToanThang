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
                    $targets[$key.'|'.$row->machine_assignment_id] = $row->id;
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
                foreach ($rows as $row) {
                    $source = $assignments->get($row->machine_assignment_id);
                    $key = $row->machine_id.'|'.$row->work_date;
                    $exact = $source && (int) $source->machine_id === (int) $row->machine_id
                        && $this->onDate($source, $row->work_date);
                    $protected = $this->protected($row);
                    $human = $row->manually_edited_at !== null;
                    if (! $exact) {
                        if ($protected || $human) {
                            $result['unresolved']++;

                            continue;
                        }
                        $candidates = $effective[$key];
                        if (count($candidates) > 1) {
                            $result['unresolved']++;

                            continue;
                        }
                        // Reassign only an empty draft. Evidence/canonical identity remains
                        // with its source unless an identical valid sibling preserves it.
                        $candidate = count($candidates) === 1 ? $candidates[0] : null;
                        if ($candidate && $this->usable($candidate) && ! $this->hasData($row)
                            && ! isset($targets[$key.'|'.$candidate->id])) {
                            $source = $candidate;
                        } elseif ($source && (int) $source->machine_id === (int) $row->machine_id
                            && ! $this->onDate($source, $row->work_date)
                            && ((! $candidate && ! $this->hasData($row)) || isset($duplicates[$this->payloadKey($row)]))) {
                            $deletes[] = $row->id;
                            $logs[] = $this->log($row, $userId, 'reconciliation.stale_row_removed',
                                'Xóa dòng nháp nằm ngoài thời gian của phân công nguồn.',
                                ['row' => array_intersect_key((array) $row, array_flip(['id', 'work_date', 'project_id', 'command_center_id', 'machine_assignment_id']))], $now);
                            $result['removed']++;

                            continue;
                        } else {
                            $result['unresolved']++;

                            continue;
                        }
                    }
                    if (! $this->usable($source) || $this->ambiguous($source, $row, $effective[$key])) {
                        $result['unresolved']++;

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
                            $result['unresolved']++;

                            continue;
                        }
                        // Narrow source boundaries only; never expand existing segments.
                        $changes['segment_start'] = max($row->segment_start ?: $start, $start);
                        $changes['segment_end'] = min($row->segment_end ?: $end, $end);
                        if ($changes['segment_start'] >= $changes['segment_end']) {
                            $result['unresolved']++;

                            continue;
                        }
                    }
                    if (! $changes) {
                        continue;
                    }
                    if ($protected) {
                        $result['unresolved']++;

                        continue;
                    }
                    $old = array_intersect_key((array) $row, $changes);
                    $updates[$row->id] = $changes;
                    $targets[$key.'|'.$source->id] = $row->id;
                    $logs[] = $this->log($row, $userId, 'reconciliation.links_repaired',
                        'Khôi phục liên kết từ đúng phân công nguồn của dòng đối chiếu.',
                        ['old' => $old, 'new' => $changes, 'machine_assignment_id' => $source->id], $now);
                    $result['repaired']++;
                }
                $this->writeUpdates($period->id, $updates, $now);
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
        return substr($assignment->time_in, 0, 10) <= $date
            && (! $assignment->time_out || substr($assignment->time_out, 0, 10) >= $date);
    }

    private function usable(object $assignment): bool
    {
        return $assignment->source_project_id !== null && $assignment->source_bch_id !== null;
    }

    private function segment(object $assignment, string $date): array
    {
        return [substr($assignment->time_in, 0, 10) === $date ? substr($assignment->time_in, 11, 8) : '00:00:00',
            $assignment->time_out && substr($assignment->time_out, 0, 10) === $date ? substr($assignment->time_out, 11, 8) : '23:59:59'];
    }

    private function withinSegment(object $assignment, object $row): bool
    {
        [$start, $end] = $this->segment($assignment, $row->work_date);

        return $row->segment_start && $row->segment_end && $row->segment_start >= $start && $row->segment_end <= $end;
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
            if ($start < $otherEnd && $otherStart < $end) {
                return true;
            }
        }

        return false;
    }

    private function protected(object $row): bool
    {
        return $row->status !== 'DRAFT' || $row->reviewed_at !== null || $row->confirmed_at !== null;
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
