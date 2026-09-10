<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\ZaloMessage;
use App\Models\ZaloSenderMachineMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ZaloSenderMachineService
{
    public function atReceipt(OcrJob $job): ?ZaloSenderMachineMapping
    {
        $matches = $this->receiptCandidates($job);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function receiptCandidates(OcrJob $job): \Illuminate\Support\Collection
    {
        $message = $job->attachment?->message;
        if (! $message?->sender_id || ! $message->received_at) {
            return collect();
        }

        return ZaloSenderMachineMapping::query()->with('machine')
            ->where('sender_id', $message->sender_id)
            ->where('valid_from', '<=', $message->received_at)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $message->received_at))
            ->get();

    }

    public function legacyCurrent(): \Illuminate\Support\Collection
    {
        $at = now()->setTimezone(config('daily_photos.capture_timezone'))->format('Y-m-d H:i:s');
        $links = \App\Models\ZaloSenderDriverLink::query()
            ->whereNotIn('sender_id', ZaloSenderMachineMapping::query()->select('sender_id'))
            ->where('valid_from', '<=', $at)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at))->get();
        $histories = \App\Models\MachineDriverHistory::query()->with('machine')
            ->whereIn('driver_id', $links->pluck('driver_id'))->where('started_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))->get()->groupBy('driver_id');

        return $links->groupBy('sender_id')->filter(fn ($group) => $group->count() === 1)
            ->map(function ($group) use ($histories) {
                $link = $group->first();
                $machines = ($histories->get($link->driver_id) ?? collect())->pluck('machine')->filter()->unique('id');
                if ($machines->count() !== 1) {
                    return null;
                }

                return (object) ['sender_id' => $link->sender_id, 'machine_id' => $machines->first()->id,
                    'machine' => $machines->first(), 'valid_from' => null];
            })->filter()->values();
    }

    public function learn(OcrJob $job, Machine $machine): void
    {
        $message = $job->attachment?->message;
        if (! $message?->sender_id || ! $message->received_at) {
            return;
        }
        $this->save($message->sender_id, $machine->id, null, $message->received_at->format('Y-m-d H:i:s'));
    }

    public function save(string $senderId, int $machineId, ?int $userId, ?string $receivedAt = null): void
    {
        DB::transaction(function () use ($senderId, $machineId, $userId, $receivedAt): void {
            $sender = ZaloMessage::query()->where('sender_id', $senderId)->oldest('id')->lockForUpdate()->first();
            if (! $sender) {
                throw ValidationException::withMessages(['sender_id' => 'Chọn người gửi đã được Collector ghi nhận.']);
            }
            Machine::query()->findOrFail($machineId);
            $history = ZaloSenderMachineMapping::query()->where('sender_id', $senderId)->lockForUpdate()->get();
            // Learning never replaces any established mapping, including a closed history.
            if ($receivedAt && $history->isNotEmpty()) {
                return;
            }
            $current = $history->whereNull('valid_to')->first();
            if ($current && $current->machine_id === $machineId) {
                return;
            }
            $at = $receivedAt ?? now()->format('Y-m-d H:i:s');
            foreach ($history->whereNull('valid_to') as $mapping) {
                $mapping->update(['valid_to' => $at, 'active_sender_id' => null]);
            }
            $mapping = ZaloSenderMachineMapping::query()->create([
                'sender_id' => $senderId, 'active_sender_id' => $senderId, 'machine_id' => $machineId,
                'valid_from' => $at, 'source' => $receivedAt ? 'OCR_MACHINE' : 'MANUAL_CORRECTION', 'created_by' => $userId,
            ]);
            ActivityLog::query()->create([
                'user_id' => $userId, 'machine_id' => $machineId, 'event' => 'zalo.sender_machine_mapped',
                'description' => 'Cập nhật máy mặc định của người gửi Zalo',
                'properties' => ['mapping_id' => $mapping->id, 'previous_mapping_ids' => $history->modelKeys(), 'source' => $mapping->source],
                'occurred_at' => now(),
            ]);
        }, 3);
    }
}
