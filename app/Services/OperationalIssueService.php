<?php

namespace App\Services;

use App\Models\DriverDocument;
use App\Models\Machine;
use App\Models\MachineDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OperationalIssueService
{
    public function __construct(private readonly OcrMonitoringService $ocrMonitoring)
    {
    }

    public function expiryDays(): int
    {
        return max(1, (int) config('mmtb.operation_center.expiry_days', 30));
    }

    public function listLimit(): int
    {
        return max(1, (int) config('mmtb.operation_center.list_limit', 20));
    }

    public function waitingHandoverQuery(): Builder
    {
        return Machine::query()->where('status', 'WAIT_HANDOVER');
    }

    public function returnedNotSyncedQuery(): Builder
    {
        return Machine::query()
            ->where('status', 'RETURNED')
            ->where(function (Builder $query) {
                $query->whereNull('returned_to_app')
                    ->orWhere('returned_to_app', false);
            });
    }

    public function missingGpsQuery(): Builder
    {
        return Machine::query()
            ->where('status', '!=', 'RETURNED')
            ->where(function (Builder $query) {
                $query->whereNull('gps_file_added')
                    ->orWhere('gps_file_added', false);
            });
    }

    public function missingDriverQuery(): Builder
    {
        return Machine::query()
            ->whereIn('status', ['HANDED_OVER', 'ACTIVE'])
            ->whereNull('current_driver_id');
    }

    public function expiryItems(?int $limit = null): Collection
    {
        $today = CarbonImmutable::today();
        $limitDate = $today->addDays($this->expiryDays());

        $machineDocuments = MachineDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('machine:id,asset_code')
            ->orderBy('expiry_date')
            ->get()
            ->map(function (MachineDocument $document) use ($today) {
                $expiryDate = CarbonImmutable::parse($document->expiry_date);

                return [
                    'source' => 'machine_document',
                    'source_id' => $document->id,
                    'owner_type' => 'machine',
                    'owner_id' => $document->machine_id,
                    'owner_label' => $document->machine?->asset_code ?? '-',
                    'doc_type' => $document->doc_type,
                    'expiry_date' => $expiryDate,
                    'days_diff' => $today->diffInDays($expiryDate, false),
                ];
            });

        $driverDocuments = DriverDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('driver:id,name')
            ->orderBy('expiry_date')
            ->get()
            ->map(function (DriverDocument $document) use ($today) {
                $expiryDate = CarbonImmutable::parse($document->expiry_date);

                return [
                    'source' => 'driver_document',
                    'source_id' => $document->id,
                    'owner_type' => 'driver',
                    'owner_id' => $document->driver_id,
                    'owner_label' => $document->driver?->name ?? '-',
                    'doc_type' => $document->doc_type,
                    'expiry_date' => $expiryDate,
                    'days_diff' => $today->diffInDays($expiryDate, false),
                ];
            });

        $items = $machineDocuments
            ->merge($driverDocuments)
            ->sortBy('expiry_date')
            ->values();

        return $limit ? $items->take($limit)->values() : $items;
    }

    public function operationCenterData(): array
    {
        $limit = $this->listLimit();

        $waitingHandoverQuery = $this->waitingHandoverQuery();
        $returnedNotSyncedQuery = $this->returnedNotSyncedQuery();
        $missingGpsQuery = $this->missingGpsQuery();
        $missingDriverQuery = $this->missingDriverQuery();

        $allExpiryItems = $this->expiryItems();
        $expiryItems = $allExpiryItems->take($limit)->values();

        $counts = [
            'waiting_handover' => (clone $waitingHandoverQuery)->count(),
            'returned_not_synced' => (clone $returnedNotSyncedQuery)->count(),
            'missing_gps' => (clone $missingGpsQuery)->count(),
            'missing_driver' => (clone $missingDriverQuery)->count(),
            'expired_documents' => $allExpiryItems
                ->filter(fn (array $item) => $item['days_diff'] < 0)
                ->count(),
            'expiring_documents' => $allExpiryItems
                ->filter(fn (array $item) => $item['days_diff'] >= 0)
                ->count(),
        ];

        $counts['total'] = array_sum($counts);

        return [
            'counts' => $counts,
            'waitingHandover' => (clone $waitingHandoverQuery)
                ->orderBy('asset_code')
                ->limit($limit)
                ->get(),
            'returnedNotSynced' => (clone $returnedNotSyncedQuery)
                ->orderBy('asset_code')
                ->limit($limit)
                ->get(),
            'missingGps' => (clone $missingGpsQuery)
                ->orderBy('asset_code')
                ->limit($limit)
                ->get(),
            'missingDriver' => (clone $missingDriverQuery)
                ->orderBy('asset_code')
                ->limit($limit)
                ->get(),
            'expiryItems' => $expiryItems,
        ];
    }

    public function notificationAlerts(): array
    {
        $alerts = [];

        if ($ocrAlert = $this->ocrMonitoring->notificationAlert()) {
            $alerts[] = $ocrAlert;
        }

        $this->waitingHandoverQuery()
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

        $this->returnedNotSyncedQuery()
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

        $this->missingGpsQuery()
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

        $this->missingDriverQuery()
            ->orderBy('asset_code')
            ->get(['id', 'asset_code'])
            ->each(function (Machine $machine) use (&$alerts) {
                $alerts[] = [
                    'key' => "machine:{$machine->id}:missing_driver",
                    'level' => 'danger',
                    'category' => 'missing_driver',
                    'title' => 'Máy chưa có tài xế',
                    'message' => "{$machine->asset_code} đang hoạt động nhưng chưa được gán tài xế.",
                    'url' => route('ops.assign-driver.form', $machine),
                    'machine_id' => $machine->id,
                    'asset_code' => $machine->asset_code,
                ];
            });

        foreach ($this->expiryItems() as $item) {
            $expired = $item['days_diff'] < 0;
            $ownerLabel = $item['owner_label'];
            $documentType = $item['doc_type'];
            $days = $item['days_diff'];

            $url = $item['owner_type'] === 'machine'
                ? route('machine-documents.index', $item['owner_id'])
                : route('drivers.show', $item['owner_id']);

            $alerts[] = [
                'key' => "{$item['source']}:{$item['source_id']}:expiry",
                'level' => $expired ? 'danger' : 'warning',
                'category' => $expired ? 'expired_document' : 'expiring_document',
                'title' => $expired ? 'Hồ sơ đã hết hạn' : 'Hồ sơ sắp hết hạn',
                'message' => $expired
                    ? "{$ownerLabel} – {$documentType} đã hết hạn ".abs($days).' ngày.'
                    : "{$ownerLabel} – {$documentType} còn {$days} ngày.",
                'url' => $url,
                'owner_type' => $item['owner_type'],
                'owner_id' => $item['owner_id'],
                'owner_label' => $ownerLabel,
                'expiry_date' => $item['expiry_date']->toDateString(),
                'days_remaining' => $days,
            ];
        }

        return $alerts;
    }
}
