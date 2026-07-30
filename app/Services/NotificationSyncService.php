<?php

namespace App\Services;

use App\Models\DriverDocument;
use App\Models\Machine;
use App\Models\MachineDocument;
use App\Models\User;
use App\Notifications\OperationalAlert;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;

class NotificationSyncService
{
    private const EXPIRY_DAYS = 30;

    public function syncForAllUsers(): array
    {
        $alerts = $this->buildAlerts();
        $users = User::query()->get();

        $created = 0;
        $resolved = 0;

        foreach ($users as $user) {
            [$userCreated, $userResolved] = $this->syncForUser($user, $alerts);
            $created += $userCreated;
            $resolved += $userResolved;
        }

        return compact('created', 'resolved');
    }

    public function syncForUser(User $user, ?array $alerts = null): array
    {
        $alerts ??= $this->buildAlerts();
        $activeKeys = collect($alerts)->pluck('key')->all();

        $existing = $user->notifications()
            ->where('type', OperationalAlert::class)
            ->get();

        $existingByKey = $existing->keyBy(
            fn (DatabaseNotification $notification) => data_get($notification->data, 'key')
        );

        $created = 0;

        foreach ($alerts as $alert) {
            if (!$existingByKey->has($alert['key'])) {
                $user->notify(new OperationalAlert($alert));
                $created++;
                continue;
            }

            $notification = $existingByKey->get($alert['key']);

            // Cập nhật nội dung nếu số ngày còn lại hoặc mô tả đã thay đổi.
            if ($notification->data !== $alert) {
                $notification->forceFill(['data' => $alert])->save();
            }
        }

        $resolvedNotifications = $existing->filter(function (DatabaseNotification $notification) use ($activeKeys) {
            $key = data_get($notification->data, 'key');

            return $key && !in_array($key, $activeKeys, true);
        });

        $resolved = $resolvedNotifications->count();

        // Điều kiện đã được xử lý thì xóa cảnh báo cũ để trung tâm thông báo luôn sạch.
        foreach ($resolvedNotifications as $notification) {
            $notification->delete();
        }

        return [$created, $resolved];
    }

    public function buildAlerts(): array
    {
        $alerts = [];
        $today = CarbonImmutable::today();
        $limitDate = $today->addDays(self::EXPIRY_DAYS);

        Machine::query()
            ->where('status', 'WAIT_HANDOVER')
            ->orderBy('asset_code')
            ->get(['id', 'asset_code'])
            ->each(function (Machine $machine) use (&$alerts) {
                $alerts[] = [
                    'key' => "machine:{$machine->id}:waiting_handover",
                    'level' => 'warning',
                    'category' => 'waiting_handover',
                    'title' => 'Máy đang chờ bàn giao',
                    'message' => "{$machine->asset_code} chưa hoàn tất bàn giao.",
                    'url' => route('machines.show', $machine),
                    'machine_id' => $machine->id,
                    'asset_code' => $machine->asset_code,
                ];
            });

        Machine::query()
            ->where('status', 'RETURNED')
            ->where(function ($query) {
                $query->whereNull('returned_to_app')
                    ->orWhere('returned_to_app', false);
            })
            ->orderBy('asset_code')
            ->get(['id', 'asset_code'])
            ->each(function (Machine $machine) use (&$alerts) {
                $alerts[] = [
                    'key' => "machine:{$machine->id}:returned_not_synced",
                    'level' => 'danger',
                    'category' => 'returned_not_synced',
                    'title' => 'Máy trả chưa đồng bộ',
                    'message' => "{$machine->asset_code} đã trả nhưng chưa đánh dấu trên ứng dụng.",
                    'url' => route('machines.show', $machine),
                    'machine_id' => $machine->id,
                    'asset_code' => $machine->asset_code,
                ];
            });

        Machine::query()
            ->where('status', '!=', 'RETURNED')
            ->where(function ($query) {
                $query->whereNull('gps_file_added')
                    ->orWhere('gps_file_added', false);
            })
            ->orderBy('asset_code')
            ->get(['id', 'asset_code'])
            ->each(function (Machine $machine) use (&$alerts) {
                $alerts[] = [
                    'key' => "machine:{$machine->id}:missing_gps",
                    'level' => 'warning',
                    'category' => 'missing_gps',
                    'title' => 'Thiếu hồ sơ GPS',
                    'message' => "{$machine->asset_code} chưa được cập nhật file GPS.",
                    'url' => route('machines.show', $machine),
                    'machine_id' => $machine->id,
                    'asset_code' => $machine->asset_code,
                ];
            });

        Machine::query()
            ->whereIn('status', ['HANDED_OVER', 'ACTIVE'])
            ->whereNull('current_driver_id')
            ->orderBy('asset_code')
            ->get(['id', 'asset_code'])
            ->each(function (Machine $machine) use (&$alerts) {
                $alerts[] = [
                    'key' => "machine:{$machine->id}:missing_driver",
                    'level' => 'danger',
                    'category' => 'missing_driver',
                    'title' => 'Máy chưa có lái',
                    'message' => "{$machine->asset_code} đang hoạt động nhưng chưa được gán lái máy.",
                    'url' => route('ops.assign-driver.form', $machine),
                    'machine_id' => $machine->id,
                    'asset_code' => $machine->asset_code,
                ];
            });

        MachineDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('machine:id,asset_code')
            ->get()
            ->each(function (MachineDocument $document) use (&$alerts, $today) {
                $expiryDate = CarbonImmutable::parse($document->expiry_date);
                $days = $today->diffInDays($expiryDate, false);
                $assetCode = $document->machine?->asset_code ?? 'Máy không xác định';
                $expired = $days < 0;

                $alerts[] = [
                    'key' => "machine_document:{$document->id}:expiry",
                    'level' => $expired ? 'danger' : 'warning',
                    'category' => $expired ? 'expired_document' : 'expiring_document',
                    'title' => $expired ? 'Hồ sơ máy đã hết hạn' : 'Hồ sơ máy sắp hết hạn',
                    'message' => $expired
                        ? "{$assetCode} – {$document->doc_type} đã hết hạn ".abs($days).' ngày.'
                        : "{$assetCode} – {$document->doc_type} còn {$days} ngày.",
                    'url' => route('machine-documents.index', $document->machine_id),
                    'machine_id' => $document->machine_id,
                    'asset_code' => $assetCode,
                    'expiry_date' => $expiryDate->toDateString(),
                    'days_remaining' => $days,
                ];
            });

        DriverDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('driver:id,name')
            ->get()
            ->each(function (DriverDocument $document) use (&$alerts, $today) {
                $expiryDate = CarbonImmutable::parse($document->expiry_date);
                $days = $today->diffInDays($expiryDate, false);
                $driverName = $document->driver?->name ?? 'Lái máy không xác định';
                $expired = $days < 0;

                $alerts[] = [
                    'key' => "driver_document:{$document->id}:expiry",
                    'level' => $expired ? 'danger' : 'warning',
                    'category' => $expired ? 'expired_document' : 'expiring_document',
                    'title' => $expired ? 'Hồ sơ lái máy đã hết hạn' : 'Hồ sơ lái máy sắp hết hạn',
                    'message' => $expired
                        ? "{$driverName} – {$document->doc_type} đã hết hạn ".abs($days).' ngày.'
                        : "{$driverName} – {$document->doc_type} còn {$days} ngày.",
                    'url' => route('driver-documents.index', $document->driver_id),
                    'driver_id' => $document->driver_id,
                    'driver_name' => $driverName,
                    'expiry_date' => $expiryDate->toDateString(),
                    'days_remaining' => $days,
                ];
            });

        return $alerts;
    }
}
