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

    public function learn(OcrJob $job, Machine $imageMachine, ?Machine $receiptMachine): array
    {
        $message = $job->attachment?->message;
        if (! $message?->sender_id || ! $message->received_at) {
            return [];
        }
        return $this->save($message->sender_id, ($receiptMachine ?? $imageMachine)->id, null,
            $message->received_at->format('Y-m-d H:i:s'), observedMachineId: $imageMachine->id);
    }

    public function save(string $senderId, int $machineId, ?int $userId, ?string $receivedAt = null, bool $effectiveNow = false, ?int $observedMachineId = null): array
    {
        return DB::transaction(function () use ($senderId, $machineId, $userId, $receivedAt, $effectiveNow, $observedMachineId): array {
            $sender = ZaloMessage::query()->where('sender_id', $senderId)->oldest('id')->lockForUpdate()->first();
            if (! $sender) {
                throw ValidationException::withMessages(['sender_id' => 'Chọn người gửi đã được Collector ghi nhận.']);
            }
            Machine::query()->findOrFail($machineId);
            $roster = app(DossierZaloRoster::class);
            $identities = $roster->identities();
            $drivers = $identities->get($senderId, collect());
            if ($drivers->count() > 1) {
                if ($receivedAt) {
                    return [];
                }
                throw ValidationException::withMessages(['sender_id' => 'Liên kết tài xế của sender đang mâu thuẫn. Cần đối chiếu.']);
            }
            $driverId = $drivers->first();
            $relatedMappings = collect();
            if ($driverId) {
                if (! \App\Models\Driver::query()->lockForUpdate()->find($driverId)) {
                    if ($receivedAt) {
                        return [];
                    }
                    throw ValidationException::withMessages(['sender_id' => 'Tài xế liên kết đã ngừng sử dụng. Cần đối chiếu.']);
                }
                $siblings = $identities->filter(fn ($ids) => $ids->count() === 1 && $ids->contains($driverId))->keys();
                $relatedMappings = ZaloSenderMachineMapping::whereIn('sender_id', $siblings)
                    ->whereNull('valid_to')->orderBy('id')->lockForUpdate()->get();
                if ($receivedAt && $observedMachineId) {
                    $at = now()->timezone(config('daily_photos.capture_timezone'))->format('Y-m-d H:i:s');
                    $assigned = $relatedMappings->pluck('machine_id')->unique()->values();
                    if ($assigned->isEmpty()) {
                        $assigned = \App\Models\MachineDriverHistory::where('driver_id', $driverId)->where('started_at', '<=', $at)
                            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $at))
                            ->orderBy('id')->lockForUpdate()->get()->pluck('machine_id')->unique()->values();
                    }
                    if ($assigned->isNotEmpty() && ($assigned->count() !== 1 || (int) $assigned->first() !== $observedMachineId)) {
                        return ['sender_id' => $senderId, 'driver_id' => $driverId,
                            'assigned_machine_ids' => $assigned->all(), 'observed_machine_id' => $observedMachineId];
                    }
                    if ($assigned->isNotEmpty() && (int) $assigned->first() !== $machineId) {
                        return [];
                    }
                }
                if ($relatedMappings->pluck('machine_id')->unique()->count() > 1) {
                    if ($receivedAt) {
                        return [];
                    }
                    throw ValidationException::withMessages(['sender_id' => 'Tài xế đang có nhiều máy mở từ dữ liệu cũ. Cần đối chiếu trước khi chuyển.']);
                }
                if ($receivedAt && $relatedMappings->contains(fn ($row) => $row->sender_id !== $senderId)) {
                    return [];
                }
            }
            $history = ZaloSenderMachineMapping::query()->where('sender_id', $senderId)->lockForUpdate()->get();
            // Learning never replaces any established mapping, including a closed history.
            if ($receivedAt && $history->isNotEmpty()) {
                return [];
            }
            $previousMachine = $relatedMappings->first()?->machine_id;
            $current = $history->whereNull('valid_to')->first();
            if ($current && $current->machine_id === $machineId && $relatedMappings->count() <= 1) {
                return [];
            }
            // First manual onboarding intentionally covers the sender's waiting
            // queue. Later edits start now and never rewrite prior history.
            $at = $receivedAt ?? (! $effectiveNow && ! $previousMachine && $history->isEmpty()
                ? (ZaloMessage::where('sender_id', $senderId)->min('received_at') ?? now()->format('Y-m-d H:i:s'))
                : now()->format('Y-m-d H:i:s'));
            $closing = $history->whereNull('valid_to')->concat($relatedMappings->where('sender_id', '!=', $senderId))->unique('id');
            foreach ($closing as $mapping) {
                $mapping->update(['valid_to' => $at, 'active_sender_id' => null]);
            }
            $mapping = ZaloSenderMachineMapping::query()->create([
                'sender_id' => $senderId, 'active_sender_id' => $senderId, 'machine_id' => $machineId,
                'valid_from' => $at, 'source' => $receivedAt ? 'OCR_MACHINE' : 'MANUAL_CORRECTION', 'created_by' => $userId,
            ]);
            ActivityLog::query()->create([
                'user_id' => $userId, 'machine_id' => $machineId, 'event' => $driverId && $previousMachine && $previousMachine !== $machineId ? 'zalo.driver_transferred' : 'zalo.sender_machine_mapped',
                'description' => 'Cập nhật máy mặc định của người gửi Zalo',
                'properties' => ['mapping_id' => $mapping->id, 'previous_mapping_ids' => $history->modelKeys(), 'closed_mapping_ids' => $closing->pluck('id')->values()->all(),
                    'source' => $mapping->source, 'driver_id' => $driverId, 'from_machine_id' => $previousMachine, 'to_machine_id' => $machineId],
                'occurred_at' => now(),
            ]);

            return [];
        }, 3);
    }
}
