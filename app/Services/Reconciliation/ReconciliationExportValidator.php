<?php

namespace App\Services\Reconciliation;

use App\Models\Machine;
use App\Models\ReconciliationPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconciliationExportValidator
{
    public function validate(ReconciliationPeriod $period, ?int $machineId = null, ?string $from = null, ?string $to = null): array
    {
        // A scoped audit clones the period; it never updates stored dates or export behavior.
        if ($from !== null || $to !== null) {
            $period = clone $period;
            $period->date_from = $from ?? $period->date_from;
            $period->date_to = $to ?? $period->date_to;
        }
        $rows = $period->rows()
            ->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
            ->whereDate('work_date', '>=', $period->date_from)->whereDate('work_date', '<=', $period->date_to)
            ->with(['machine:id,asset_code', 'commandCenter:id,name', 'assignment.bchResolution'])
            ->orderBy('machine_id')
            ->orderBy('work_date')
            ->orderBy('segment_start')
            ->get();

        $blocking = collect();
        $warnings = collect();

        if ($rows->isEmpty() && in_array($period->status, ['CONFIRMED', 'EXPORTED'], true)) {
            $blocking->push('Kỳ đối chiếu chưa có dòng dữ liệu để xuất.');
        }

        $unassignedMachines = Machine::query()
            ->when($machineId, fn ($q) => $q->whereKey($machineId))
            ->where('status', 'ACTIVE')
            ->where(function ($query) use ($period): void {
                $query->whereNull('created_at')
                    ->orWhere('created_at', '<=', $period->date_to->copy()->endOfDay());
            })
            ->whereDoesntHave('assignments', function ($query) use ($period): void {
                $query->where('time_in', '<=', $period->date_to->copy()->endOfDay())
                    ->where(function ($assignmentQuery) use ($period): void {
                        $assignmentQuery->whereNull('time_out')
                            ->orWhere('time_out', '>=', $period->date_from->copy()->startOfDay());
                    });
            })
            ->orderBy('asset_code')
            ->get(['id', 'asset_code']);
        $ids = $unassignedMachines->pluck('id')->merge($rows->pluck('machine_id'))->unique();
        $history = $ids->isEmpty() ? collect() : DB::table('machine_assignments as a')->leftJoin('machine_assignment_bch_resolutions as br', 'br.machine_assignment_id', '=', 'a.id')
            ->whereIn('a.machine_id', $ids)->get(['a.*', DB::raw('COALESCE(a.command_center_id, br.command_center_id) as source_bch_id')]);
        $events = $ids->isEmpty() ? collect() : DB::table('machine_events')->whereIn('machine_id', $ids)
            ->whereIn('type', ['RETURN', 'HANDOVER', 'TRANSFER'])->get(['id', 'machine_id', 'type', 'occurred_at']);
        $states = new AssignmentTimelineState($history, $events);
        $ownership = new DayBasedAssignmentOwnership($history, $events);
        $timeline = $history->filter(fn ($a) => AssignmentInterval::valid($a) && (string) $a->time_in <= $period->date_to->toDateString().' 23:59:59'
            && (! $a->time_out || (string) $a->time_out >= $period->date_from->toDateString().' 00:00:00'))->groupBy('machine_id');
        foreach ($unassignedMachines as $machine) {
            $context = $states->context($machine->id, $period->date_from->toDateString().' 00:00:00', $period->date_to->toDateString().' 23:59:59');
            if (! AssignmentTimelineState::isUnassigned($context['timeline_context'])) {
                $blocking->push($machine->asset_code.': đang hoạt động nhưng không có lịch phân BCH trong kỳ. ['.$context['timeline_context'].']');
            }
        }

        $referenceIds = $rows->flatMap(fn ($row) => collect($row->daily_intervals ?? [])->pluck('canonical_interval_id'))->filter()->unique();
        $canonicalReferences = $referenceIds->isEmpty() ? collect() : DB::table('daily_photo_intervals as i')
            ->join('daily_photo_cases as c', 'c.id', '=', 'i.daily_photo_case_id')->whereIn('i.id', $referenceIds)
            ->get(['i.id', 'i.raw_start_at', 'i.raw_end_at', 'c.machine_id', 'c.work_date', 'c.machine_assignment_id'])->keyBy('id');
        $jobIds = $rows->flatMap(fn ($row) => $row->daily_ocr_job_ids ?? [])->unique();
        $jobReferences = $jobIds->isEmpty() ? collect() : DB::table('ocr_jobs as j')
            ->leftJoin('daily_photo_cases as c', 'c.id', '=', 'j.daily_photo_case_id')->whereIn('j.id', $jobIds)
            ->get(['j.id', 'j.machine_id', 'j.extracted_date', 'j.daily_photo_case_id', 'c.machine_assignment_id'])->keyBy('id');
        foreach ($rows as $row) {
            $label = $this->rowLabel($row);
            $day = $ownership->resolve($row->machine_id, $row->work_date->toDateString());
            $context = $day['context'];
            $unassigned = AssignmentTimelineState::isUnassigned($context['timeline_context']);
            $normalized = $unassigned && $row->machine_assignment_id === null && $row->project_id === null && $row->command_center_id === null;
            if ($normalized) {
                foreach (DailyTimeAllocator::KINDS as $kind) {
                    if (! $row->{$kind.'_start'} || ! $row->{$kind.'_end'}) {
                        continue;
                    }
                    $start = $row->work_date->copy()->setTimeFromTimeString($row->{$kind.'_start'});
                    $end = $row->work_date->copy()->setTimeFromTimeString($row->{$kind.'_end'});
                    if ($kind === 'overtime_evening' && $end->lt($start)) {
                        $end->addDay();
                    }
                    $hours = $states->context($row->machine_id, $start->toDateTimeString(), $end->toDateTimeString());
                    if ($start->gte($end) || ! AssignmentTimelineState::isUnassigned($hours['timeline_context'])
                        || $start->toDateTimeString() < $row->work_date->toDateString().' '.$row->segment_start
                        || $end->toDateTimeString() > $row->work_date->toDateString().' '.$row->segment_end) {
                        $blocking->push($label.': UNASSIGNED_TIME_CONFLICT '.$kind);
                    }
                }
                foreach ($row->daily_ocr_job_ids ?? [] as $id) {
                    $reference = $jobReferences->get($id);
                    if ($reference && ($reference->machine_assignment_id !== null
                        || ($reference->machine_id !== null && (int) $reference->machine_id !== (int) $row->machine_id)
                        || ($reference->extracted_date !== null && substr($reference->extracted_date, 0, 10) !== $row->work_date->toDateString()))) {
                        $blocking->push($label.': CANONICAL_OCR_CONFLICT #'.$id);
                    }
                }
                foreach ($row->daily_intervals ?? [] as $part) {
                    if ($id = $part['canonical_interval_id'] ?? null) {
                        $reference = $canonicalReferences->get($id);
                        if (! $reference || $reference->machine_assignment_id !== null || (int) $reference->machine_id !== (int) $row->machine_id
                            || substr($reference->work_date, 0, 10) !== $row->work_date->toDateString()
                            || $reference->raw_start_at < $row->work_date->toDateString().' '.$row->segment_start
                            || $reference->raw_end_at > $row->work_date->toDateString().' '.$row->segment_end
                            || ! AssignmentTimelineState::isUnassigned($states->context($row->machine_id, $reference->raw_start_at, $reference->raw_end_at)['timeline_context'])) {
                            $blocking->push($label.': CANONICAL_CONFLICT #'.$id);
                        }
                    }
                }
            }
            if ($unassigned && ! $normalized) {
                $blocking->push($label.': dữ liệu ngoài lịch BCH có hiệu lực; giữ evidence để kiểm tra. ['.$context['timeline_context'].']');
            }

            if (in_array($context['timeline_context'], ['LIFECYCLE_AMBIGUITY', 'LIFECYCLE_ASSIGNMENT_CONFLICT'], true)) {
                $blocking->push($label.': '.$context['timeline_context'].' event #'.($context['last_lifecycle_event_id'] ?? '').' '.$context['last_lifecycle_at']);
            }
            if ($context['timeline_context'] === 'INVALID_TIMELINE') {
                $blocking->push($label.': INVALID_TIMELINE '.json_encode($context['assignment_issues']));
            }
            if ($row->machine_assignment_id === null && ! $normalized) {
                $blocking->push($label.': relationship Không BCH chưa được timeline chứng minh. ['.$context['timeline_context'].']');
            }
            if ($row->machine_assignment_id && ! $row->assignment) {
                $blocking->push($label.': không tìm thấy phân công nguồn.');
            }
            if ($row->machine_assignment_id !== null) {
                foreach ($row->daily_intervals ?? [] as $part) {
                    if ($id = $part['canonical_interval_id'] ?? null) {
                        $reference = $canonicalReferences->get($id);
                        if (! $reference || (int) $reference->machine_assignment_id !== (int) $row->machine_assignment_id
                            || (int) $reference->machine_id !== (int) $row->machine_id
                            || substr($reference->work_date, 0, 10) !== $row->work_date->toDateString()) {
                            $blocking->push($label.': CANONICAL_CONFLICT #'.$id);
                        }
                    }
                }
                foreach ($row->daily_ocr_job_ids ?? [] as $id) {
                    $reference = $jobReferences->get($id);
                    if ($reference && $reference->daily_photo_case_id !== null
                        && ((int) $reference->machine_assignment_id !== (int) $row->machine_assignment_id
                            || (int) $reference->machine_id !== (int) $row->machine_id
                            || ($reference->extracted_date && substr($reference->extracted_date, 0, 10) !== $row->work_date->toDateString()))) {
                        $blocking->push($label.': CANONICAL_OCR_CONFLICT #'.$id);
                    }
                }
            }
            if ($assignment = $row->assignment) {
                $owner = $day['assignment'];
                $sourceBchId = $owner?->source_bch_id ?? $owner?->command_center_id;
                if (! $owner || (int) $owner->id !== (int) $assignment->id
                    || ! AssignmentInterval::contains($owner, $row, $row->work_date->toDateString())
                    || (int) $row->machine_id !== (int) $owner->machine_id
                    || (int) $row->project_id !== (int) $owner->project_id
                    || (int) $row->command_center_id !== (int) $sourceBchId) {
                    $blocking->push($label.': dòng đối chiếu không còn khớp phân công nguồn theo BCH phụ trách ngày; cần Repair hoặc review dữ liệu được bảo vệ.');
                }
            }
            if ($day['reason'] !== null) {
                $blocking->push($label.': '.$day['reason']);
                if ($day['reason'] === 'TRUE_ASSIGNMENT_OVERLAP') {
                    $warnings->push($label.': phân công nguồn thực sự chồng lấn, cần kiểm tra.');
                }
            }
            if (! $row->command_center_id && ! $normalized) {
                $blocking->push($label.': chưa xác định BCH.');
            }

            if (! $row->project_id && ! $normalized) {
                $blocking->push($label.': chưa xác định dự án.');
            }

            if (! $row->segment_start || ! $row->segment_end) {
                $blocking->push($label.': thiếu khoảng giờ thuộc BCH.');
            }

            $hasLogbookDuration = collect([
                $row->regular_minutes,
                $row->lunch_minutes,
                $row->ot_afternoon_minutes,
                $row->ot_evening_minutes,
            ])->contains(fn ($minutes) => (int) $minutes > 0);
            $hasAllocatedTimes = $row->regular_morning_start || $row->regular_afternoon_start
                || $row->overtime_lunch_start || $row->overtime_afternoon_start
                || $row->overtime_evening_start;

            if ($hasLogbookDuration && ! $hasAllocatedTimes) {
                $blocking->push($label.': chưa phân bổ giờ nhật trình vào các cột hành chính/tăng ca.');
            }
        }

        $rows->groupBy(fn ($row) => $row->machine_id.'|'.$row->work_date?->format('Y-m-d'))
            ->each(function (Collection $dailyRows) use ($blocking, $warnings): void {
                $dailyRows = $dailyRows->values();
                if (config('daily_photos.enabled') && $dailyRows->sum('regular_minutes') > 420) {
                    $blocking->push($this->rowLabel($dailyRows->first()).': tổng hành chính ở các BCH vượt 7 tiếng.');
                }

                for ($left = 0; $left < $dailyRows->count(); $left++) {
                    for ($right = $left + 1; $right < $dailyRows->count(); $right++) {
                        $first = $dailyRows[$left];
                        $second = $dailyRows[$right];

                        $pairLabel = $this->pairLabel($first, $second);
                        $blocking->push($pairLabel.': DAY_OWNERSHIP_DUPLICATE máy/ngày có nhiều dòng BCH.');
                        [$firstStart, $firstEnd] = $this->effectiveRange($first);
                        [$secondStart, $secondEnd] = $this->effectiveRange($second);

                        if (! $firstStart || ! $firstEnd || ! $secondStart || ! $secondEnd) {
                            continue;
                        }

                        if ($firstStart === $secondStart && $firstEnd === $secondEnd) {
                            $blocking->push($pairLabel.': hai BCH có khoảng giờ giống hệt nhau.');

                            continue;
                        }

                        if ($this->overlaps($firstStart, $firstEnd, $secondStart, $secondEnd)) {
                            $warnings->push($pairLabel.': khoảng giờ các dòng đối chiếu chồng lấn; cần kiểm tra liên kết và dữ liệu.');
                        }
                    }
                }
            });

        return [
            'blocking' => $blocking->unique()->values(),
            'warnings' => $warnings->unique()->values(),
            'can_export' => $blocking->isEmpty(),
        ];
    }

    private function effectiveRange($row): array
    {
        return [
            $row->confirmed_check_in ?: $row->segment_start,
            $row->confirmed_check_out ?: $row->segment_end,
        ];
    }

    private function overlaps(string $firstStart, string $firstEnd, string $secondStart, string $secondEnd): bool
    {
        return AssignmentInterval::overlaps($firstStart, $firstEnd, $secondStart, $secondEnd);
    }

    private function rowLabel($row): string
    {
        return sprintf(
            '%s ngày %s',
            $row->machine?->asset_code ?? 'Máy #'.$row->machine_id,
            $row->work_date?->format('d/m/Y')
        );
    }

    private function pairLabel($first, $second): string
    {
        return sprintf(
            '%s (%s và %s)',
            $this->rowLabel($first),
            $first->commandCenter?->name ?? 'BCH chưa rõ',
            $second->commandCenter?->name ?? 'BCH chưa rõ'
        );
    }
}
