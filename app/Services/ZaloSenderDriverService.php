<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineDriverHistory;
use App\Models\OcrJob;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ZaloSenderDriverService
{
    public function link(array $data, int $userId): void
    {
        DB::transaction(function () use ($data, $userId) {
            $sender = \App\Models\ZaloMessage::query()->where('sender_id', $data['sender_id'])->orderBy('id')->lockForUpdate()->first();
            if (!$sender) throw ValidationException::withMessages(['sender_id' => 'Chọn người gửi đã được Collector ghi nhận.']);
            Driver::query()->lockForUpdate()->findOrFail($data['driver_id']);
            $overlap = DB::table('zalo_sender_driver_links')->where('sender_id', $data['sender_id'])
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $data['valid_from']))
                ->when($data['valid_to'] ?? null, fn ($q, $end) => $q->where('valid_from', '<', $end))->exists();
            if ($overlap) throw ValidationException::withMessages(['sender_id' => 'Khoảng thời gian ánh xạ bị trùng. Kết thúc ánh xạ cũ trước.']);
            $id = DB::table('zalo_sender_driver_links')->insertGetId([...$data, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            ActivityLog::create(['user_id' => $userId, 'event' => 'zalo.sender_linked', 'description' => 'Ánh xạ người gửi Zalo với lái máy', 'properties' => ['link_id' => $id, ...$data], 'occurred_at' => now()]);
        });
    }

    public function close(int $id, string $end, int $userId): void
    {
        DB::transaction(function () use ($id, $end, $userId) {
            $link = DB::table('zalo_sender_driver_links')->where('id', $id)->lockForUpdate()->first();
            abort_unless($link, 404);
            if ($link->valid_to || $end <= $link->valid_from) throw ValidationException::withMessages(['valid_to' => 'Chọn thời điểm kết thúc sau khi bắt đầu cho ánh xạ đang mở.']);
            DB::table('zalo_sender_driver_links')->where('id', $id)->update(['valid_to' => $end, 'updated_at' => now()]);
            ActivityLog::create(['user_id' => $userId, 'event' => 'zalo.sender_link_closed', 'description' => 'Kết thúc ánh xạ người gửi',
                'properties' => ['link_id' => $id, 'before' => (array) $link, 'valid_to' => $end], 'occurred_at' => now()]);
        });
    }

    public function resolve(OcrJob $job, ?string $date, ?string $time): ?Machine
    {
        $sender = $job->attachment?->message?->sender_id;
        if (!$sender || !$date || !$time) return null;
        $at = $date.' '.$time;
        $links = DB::table('zalo_sender_driver_links')->where('sender_id', $sender)->where('valid_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at))->get();
        if ($links->count() !== 1 || !Driver::find($links[0]->driver_id)) return null;
        $ids = MachineDriverHistory::query()->where('driver_id', $links[0]->driver_id)->where('started_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))->pluck('machine_id')->unique();
        return $ids->count() === 1 ? Machine::find($ids->first()) : null;
    }
}
