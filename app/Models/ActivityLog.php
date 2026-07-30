<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['event'] ?? null, fn (Builder $q, string $event) => $q->where('event', $event))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $userId) => $q->where('user_id', $userId))
            ->when($filters['machine_id'] ?? null, fn (Builder $q, $machineId) => $q->where('machine_id', $machineId))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('occurred_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('occurred_at', '<=', $date))
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('description', 'like', "%{$term}%")
                        ->orWhereHas('machine', fn (Builder $machine) => $machine
                            ->where('asset_code', 'like', "%{$term}%")
                            ->orWhere('chassis_no', 'like', "%{$term}%")
                            ->orWhere('plate_no', 'like', "%{$term}%"))
                        ->orWhereHas('user', fn (Builder $user) => $user
                            ->where('name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%"));
                });
            });
    }

    public function eventLabel(): string
    {
        return match ($this->event) {
            'machine.created' => 'Tạo thiết bị',
            'machine.updated' => 'Cập nhật thiết bị',
            'machine.deleted' => 'Xóa thiết bị',
            'machine.handover' => 'Bàn giao',
            'machine.activate' => 'Kích hoạt',
            'machine.transfer' => 'Điều chuyển',
            'machine.return' => 'Trả máy',
            'machine.assign_driver' => 'Gán/đổi tài xế',
            'machine.document_created' => 'Thêm hồ sơ máy',
            'machine.document_updated' => 'Cập nhật hồ sơ máy',
            'machine.document_deleted' => 'Xóa hồ sơ máy',
            'driver.created' => 'Tạo tài xế',
            'driver.updated' => 'Cập nhật tài xế',
            'driver.deleted' => 'Xóa tài xế',
            'driver.document_created' => 'Thêm hồ sơ tài xế',
            'driver.document_updated' => 'Cập nhật hồ sơ tài xế',
            'driver.document_deleted' => 'Xóa hồ sơ tài xế',
            'project.created' => 'Tạo dự án',
            'project.updated' => 'Cập nhật dự án',
            'project.deleted' => 'Xóa dự án',
            'command_center.created' => 'Tạo ban chỉ huy',
            'command_center.updated' => 'Cập nhật ban chỉ huy',
            'command_center.deleted' => 'Xóa ban chỉ huy',
            default => $this->event,
        };
    }
}
