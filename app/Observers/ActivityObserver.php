<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\CommandCenter;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineDocument;
use App\Models\MachineEvent;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class ActivityObserver
{
    private const HIDDEN_FIELDS = [
        'password',
        'remember_token',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function created(Model $model): void
    {
        $this->write($model, 'created');
    }

    public function updated(Model $model): void
    {
        $changes = Arr::except($model->getChanges(), self::HIDDEN_FIELDS);

        if ($changes === []) {
            return;
        }

        $old = [];
        foreach (array_keys($changes) as $field) {
            $old[$field] = $model->getOriginal($field);
        }

        $this->write($model, 'updated', [
            'old' => $old,
            'new' => $changes,
        ]);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted');
    }

    private function write(Model $model, string $action, array $properties = []): void
    {
        // Không tự ghi log cho chính bảng log.
        if ($model instanceof ActivityLog) {
            return;
        }

        [$event, $description] = $this->resolveEvent($model, $action);

        ActivityLog::query()->create([
            'user_id' => auth()->id(),
            'machine_id' => $this->resolveMachineId($model),
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'event' => $event,
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'occurred_at' => now(),
        ]);
    }

    private function resolveMachineId(Model $model): ?int
    {
        if ($model instanceof Machine) {
            return (int) $model->getKey();
        }

        if ($model instanceof MachineAssignment ||
            $model instanceof MachineEvent ||
            $model instanceof MachineDocument) {
            return $model->machine_id ? (int) $model->machine_id : null;
        }

        return null;
    }

    private function resolveEvent(Model $model, string $action): array
    {
        if ($model instanceof MachineEvent && $action === 'created') {
            return $this->machineEventDescription($model);
        }

        if ($model instanceof MachineAssignment) {
            return [
                "machine.assignment_{$action}",
                $action === 'created'
                    ? 'Tạo đợt phân công thiết bị'
                    : ($action === 'updated' ? 'Cập nhật đợt phân công thiết bị' : 'Xóa đợt phân công thiết bị'),
            ];
        }

        $map = [
            Machine::class => ['machine', 'thiết bị'],
            MachineDocument::class => ['machine.document', 'hồ sơ máy'],
            Driver::class => ['driver', 'tài xế'],
            DriverDocument::class => ['driver.document', 'hồ sơ tài xế'],
            Project::class => ['project', 'dự án'],
            CommandCenter::class => ['command_center', 'ban chỉ huy'],
        ];

        [$prefix, $label] = $map[$model::class] ?? ['record', 'dữ liệu'];

        $verb = match ($action) {
            'created' => 'Tạo',
            'updated' => 'Cập nhật',
            'deleted' => 'Xóa',
            default => ucfirst($action),
        };

        return ["{$prefix}.{$action}", "{$verb} {$label}: {$this->modelName($model)}"];
    }

    private function machineEventDescription(MachineEvent $event): array
    {
        $machine = Machine::query()->find($event->machine_id);
        $assetCode = $machine?->asset_code ?: ('Máy #' . $event->machine_id);

        return match (strtoupper((string) $event->type)) {
            'HANDOVER' => ['machine.handover', "Bàn giao {$assetCode} vào dự án"],
            'ACTIVATE' => ['machine.activate', "Kích hoạt {$assetCode} trên hệ thống"],
            'TRANSFER' => ['machine.transfer', "Điều chuyển {$assetCode}"],
            'RETURN' => ['machine.return', "Trả {$assetCode} về công ty"],
            'DRIVER_ASSIGNED', 'ASSIGN_DRIVER', 'CHANGE_DRIVER' =>
                ['machine.assign_driver', "Gán hoặc đổi tài xế cho {$assetCode}"],
            default => ['machine.event_created', "Tạo sự kiện {$event->type} cho {$assetCode}"],
        };
    }

    private function modelName(Model $model): string
    {
        foreach (['asset_code', 'name', 'code', 'title', 'document_type'] as $field) {
            if (!empty($model->{$field})) {
                return (string) $model->{$field};
            }
        }

        return '#' . $model->getKey();
    }
}
