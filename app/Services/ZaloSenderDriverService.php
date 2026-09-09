<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineDriverHistory;
use App\Models\OcrJob;
use App\Models\ActivityLog;
use App\Models\ZaloMessage;
use App\Models\ZaloSenderDriverLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ZaloSenderDriverService
{
    public function link(array $data, int $userId): void
    {
        DB::transaction(function () use ($data, $userId) {
            $sender = ZaloMessage::query()->where('sender_id', $data['sender_id'])->orderBy('id')->lockForUpdate()->first();
            if (!$sender) throw ValidationException::withMessages(['sender_id' => 'Chọn người gửi đã được Collector ghi nhận.']);
            Driver::query()->lockForUpdate()->findOrFail($data['driver_id']);
            $overlap = ZaloSenderDriverLink::query()->where('sender_id', $data['sender_id'])
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $data['valid_from']))
                ->when($data['valid_to'] ?? null, fn ($q, $end) => $q->where('valid_from', '<', $end))->exists();
            if ($overlap) throw ValidationException::withMessages(['sender_id' => 'Khoảng thời gian ánh xạ bị trùng. Kết thúc ánh xạ cũ trước.']);
            $id = ZaloSenderDriverLink::query()->create([...$data, 'created_by' => $userId])->id;
            ActivityLog::create(['user_id' => $userId, 'event' => 'zalo.sender_linked', 'description' => 'Ánh xạ người gửi Zalo với lái máy', 'properties' => ['link_id' => $id, ...$data], 'occurred_at' => now()]);
        });
    }

    public function close(int $id, string $end, int $userId): void
    {
        DB::transaction(function () use ($id, $end, $userId) {
            $preview = ZaloSenderDriverLink::query()->find($id);
            abort_unless($preview, 404);
            ZaloMessage::query()->where('sender_id', $preview->sender_id)->orderBy('id')->lockForUpdate()->first();
            $link = ZaloSenderDriverLink::query()->lockForUpdate()->findOrFail($id);
            if ($link->valid_to || $end <= $link->valid_from) throw ValidationException::withMessages(['valid_to' => 'Chọn thời điểm kết thúc sau khi bắt đầu cho ánh xạ đang mở.']);
            $before = $link->getOriginal();
            $link->update(['valid_to' => $end]);
            ActivityLog::create(['user_id' => $userId, 'event' => 'zalo.sender_link_closed', 'description' => 'Kết thúc ánh xạ người gửi',
                'properties' => ['link_id' => $id, 'before' => $before, 'valid_to' => $end], 'occurred_at' => now()]);
        });
    }

    public function resolve(OcrJob $job, ?string $date, ?string $time): ?Machine
    {
        return $this->resolveWithProvenance($job, $date, $time)['machine'];
    }

    /**
     * Resolve against the OCR capture wall-clock time. Dates in these tables are
     * intentionally compared as naive local values; no global timezone is changed.
     */
    public function resolveWithProvenance(OcrJob $job, ?string $date, ?string $time): array
    {
        $sender = $job->attachment?->message?->sender_id;
        if (!$sender || !$date || !$time) return $this->result('MISSING_INPUT');
        $at = $date.' '.(strlen($time) === 5 ? $time.':00' : $time);

        return DB::transaction(function () use ($sender, $at): array {
            ZaloMessage::query()->where('sender_id', $sender)->oldest('id')->lockForUpdate()->first();
            $links = ZaloSenderDriverLink::query()->where('sender_id', $sender)->where('valid_from', '<=', $at)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at))
                ->orderBy('id')->lockForUpdate()->get();
            if ($links->isEmpty()) return $this->result('NO_LINK');
            if ($links->count() !== 1) return $this->result('AMBIGUOUS_LINK', linkIds: $links->modelKeys());

            $link = $links->first();
            if (!Driver::query()->lockForUpdate()->find($link->driver_id)) {
                return $this->result('DRIVER_NOT_FOUND', $link);
            }

            $histories = MachineDriverHistory::query()->where('driver_id', $link->driver_id)->where('started_at', '<=', $at)
                ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))
                ->orderBy('id')->lockForUpdate()->get();
            if ($histories->isEmpty()) return $this->result('NO_MACHINE_HISTORY', $link);

            $machineIds = $histories->pluck('machine_id')->unique()->values();
            if ($machineIds->count() !== 1) {
                return $this->result('AMBIGUOUS_MACHINE_HISTORY', $link, historyIds: $histories->modelKeys(), machineIds: $machineIds->all());
            }

            $machine = Machine::query()->find($machineIds->first());
            if (!$machine) return $this->result('MACHINE_NOT_FOUND', $link, historyIds: $histories->modelKeys(), machineIds: $machineIds->all());

            $history = $histories->firstWhere('machine_id', $machine->id);

            return $this->result('RESOLVED', $link, $history, $machine, $histories->modelKeys(), $machineIds->all());
        }, 3);
    }

    private function result(
        string $status,
        ?ZaloSenderDriverLink $link = null,
        ?MachineDriverHistory $history = null,
        ?Machine $machine = null,
        array $historyIds = [],
        array $machineIds = [],
        array $linkIds = [],
    ): array {
        return [
            'status' => $status,
            'machine' => $machine,
            'link' => $link,
            'history' => $history,
            'candidate_link_ids' => $linkIds ?: ($link ? [$link->id] : []),
            'candidate_history_ids' => $historyIds,
            'candidate_machine_ids' => $machineIds,
        ];
    }
}
