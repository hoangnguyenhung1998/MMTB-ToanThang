<?php

namespace App\Services\Reconciliation;

use App\Models\JournalRow;
use App\Models\MachineAssignment;
use App\Models\ReconciliationPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ReconciliationGenerator
{
    private const JOURNAL_REVIEW_STATUSES = ['APPROVED', 'CORRECTED'];

    public function __construct(private readonly ReconciliationTimeAllocator $timeAllocator)
    {
    }

    public function generate(ReconciliationPeriod $period): ReconciliationPeriod
    {
        if ($period->date_from->gt($period->date_to)) {
            throw new InvalidArgumentException('Ngày bắt đầu kỳ đối chiếu phải nhỏ hơn hoặc bằng ngày kết thúc.');
        }

        if ($period->rows()->whereIn('status', ['REVIEWED', 'CONFIRMED'])->exists()) {
            throw new RuntimeException('Không thể tạo lại kỳ đã có dữ liệu được duyệt hoặc xác nhận.');
        }

        return DB::transaction(function () use ($period) {
            $period->rows()->delete();

            $periodStart = $period->date_from->copy()->startOfDay();
            $periodEnd = $period->date_to->copy()->endOfDay();

            $assignments = MachineAssignment::query()
                ->where('time_in', '<=', $periodEnd)
                ->where(function ($query) use ($periodStart) {
                    $query->whereNull('time_out')
                        ->orWhere('time_out', '>=', $periodStart);
                })
                ->orderBy('machine_id')
                ->orderBy('time_in')
                ->orderBy('id')
                ->get();

            $machineIds = $assignments->pluck('machine_id')->unique()->values();

            $journalRows = $machineIds->isEmpty()
                ? collect()
                : JournalRow::query()
                    ->whereBetween('work_date', [$period->date_from, $period->date_to])
                    ->whereHas('document', fn ($query) => $query->whereIn('machine_id', $machineIds))
                    ->whereHas('document.ocrJob', fn ($query) => $query
                        ->whereIn('review_status', self::JOURNAL_REVIEW_STATUSES))
                    ->with('document:id,machine_id,ocr_job_id')
                    ->orderBy('work_date')
                    ->orderBy('start_time')
                    ->get()
                    ->groupBy(fn (JournalRow $row) => implode('|', [
                        $row->document->machine_id,
                        $row->work_date?->format('Y-m-d'),
                    ]));

            $driverHistories = $machineIds->isEmpty()
                ? collect()
                : DB::table('machine_driver_histories')
                    ->whereIn('machine_id', $machineIds)
                    ->where('started_at', '<=', $periodEnd)
                    ->where(function ($query) use ($periodStart) {
                        $query->whereNull('ended_at')
                            ->orWhere('ended_at', '>=', $periodStart);
                    })
                    ->orderBy('machine_id')
                    ->orderBy('started_at')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('machine_id');

            $rows = [];
            $seenAssignmentByMachine = [];
            $now = now();

            foreach ($assignments as $assignment) {
                $assignmentStart = Carbon::parse($assignment->time_in);
                $assignmentEnd = $assignment->time_out
                    ? Carbon::parse($assignment->time_out)
                    : $periodEnd->copy();

                $from = $assignmentStart->copy()->startOfDay()->max($periodStart->copy());
                $to = $assignmentEnd->copy()->startOfDay()->min($period->date_to->copy()->startOfDay());

                if ($from->gt($to)) {
                    continue;
                }

                $isTransferIn = isset($seenAssignmentByMachine[$assignment->machine_id]);
                $seenAssignmentByMachine[$assignment->machine_id] = true;
                $histories = $driverHistories->get($assignment->machine_id, collect());

                for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
                    $changeType = null;
                    $changeNote = null;

                    if ($date->isSameDay($assignmentStart)) {
                        $changeType = $isTransferIn ? 'TRANSFER_IN' : 'HANDOVER';
                        $changeNote = $isTransferIn
                            ? 'Máy được điều chuyển vào BCH/dự án trong ngày.'
                            : 'Máy bắt đầu được bàn giao vào BCH/dự án trong ngày.';
                    }

                    if ($assignment->time_out && $date->isSameDay($assignmentEnd)) {
                        $changeType = $changeType ? $changeType.'_AND_END' : 'ASSIGNMENT_END';
                        $changeNote = trim(($changeNote ? $changeNote.' ' : '').'Máy kết thúc phân công trong ngày.');
                    }

                    $key = implode('|', [
                        $assignment->machine_id,
                        $date->toDateString(),
                        $assignment->id,
                    ]);

                    $segmentStart = $this->segmentStart($assignmentStart, $date);
                    $segmentEnd = $this->segmentEnd($assignmentEnd, $date, $assignment->time_out !== null);
                    $sourceRows = collect($journalRows->get(
                        $assignment->machine_id.'|'.$date->toDateString(),
                        []
                    ))->filter(fn (JournalRow $row) => $this->belongsToSegment($row, $segmentStart, $segmentEnd));
                    $allocation = $this->timeAllocator->allocate($sourceRows);

                    $rows[$key] = [
                        'reconciliation_period_id' => $period->id,
                        'machine_assignment_id' => $assignment->id,
                        'machine_id' => $assignment->machine_id,
                        'work_date' => $date->toDateString(),
                        'segment_start' => $segmentStart,
                        'segment_end' => $segmentEnd,
                        'project_id' => $assignment->project_id,
                        'command_center_id' => $assignment->command_center_id,
                        'driver_id' => $this->driverForDate($histories, $date),
                        'change_type' => $changeType,
                        'change_note' => $changeNote,
                        ...$allocation,
                        'work_location' => $sourceRows->pluck('work_location')->filter()->unique()->implode(', ') ?: null,
                        'work_content' => $sourceRows->pluck('work_content')->filter()->unique()->implode("\n") ?: null,
                        'explanation' => $sourceRows->pluck('error_explanation')->filter()->unique()->implode("\n") ?: null,
                        'status' => 'DRAFT',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                DB::table('reconciliation_rows')->insert($chunk);
            }

            $period->update([
                'status' => 'GENERATED',
                'generated_at' => $now,
            ]);

            return $period->fresh(['rows']);
        });
    }

    private function belongsToSegment(JournalRow $row, string $segmentStart, string $segmentEnd): bool
    {
        if (!$row->start_time) {
            return false;
        }

        return $row->start_time >= $segmentStart && $row->start_time <= $segmentEnd;
    }

    private function segmentStart(Carbon $assignmentStart, Carbon $date): string
    {
        return $date->isSameDay($assignmentStart)
            ? $assignmentStart->format('H:i:s')
            : '00:00:00';
    }

    private function segmentEnd(Carbon $assignmentEnd, Carbon $date, bool $hasTimeOut): string
    {
        if ($hasTimeOut && $date->isSameDay($assignmentEnd)) {
            return $assignmentEnd->format('H:i:s');
        }

        return '23:59:59';
    }

    private function driverForDate(Collection $histories, Carbon $date): ?int
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        $history = $histories
            ->filter(function ($history) use ($dayStart, $dayEnd) {
                $startedAt = Carbon::parse($history->started_at);
                $endedAt = $history->ended_at ? Carbon::parse($history->ended_at) : null;

                return $startedAt->lte($dayEnd)
                    && ($endedAt === null || $endedAt->gte($dayStart));
            })
            ->sortByDesc(fn ($history) => Carbon::parse($history->started_at)->timestamp)
            ->first();

        return $history ? (int) $history->driver_id : null;
    }
}
