<?php

namespace App\Http\Controllers;

use App\Models\DriverDocument;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const MACHINE_DOC_TYPES = ['Đăng ký', 'Đăng kiểm', 'Kiểm định', 'Bảo hiểm'];
    private const DRIVER_DOC_TYPES = ['Thẻ ATLĐ', 'Giấy khám sức khỏe', 'Bảo hiểm tai nạn'];
    private const EXPIRY_DAYS = 30;

    public function index(): View
    {
        $today = CarbonImmutable::today();
        $limitDate = $today->addDays(self::EXPIRY_DAYS);
        $statuses = ['WAIT_HANDOVER', 'HANDED_OVER', 'ACTIVE', 'RETURNED'];
        $companies = ['VINCONS', 'VINALPHA'];

        $totalMachines = Machine::count();

        $statusCounts = Machine::query()
            ->selectRaw('status, count(*) total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value);

        $companyStatusRows = Machine::query()
            ->selectRaw('company, status, count(*) total')
            ->groupBy('company', 'status')
            ->get();

        $companyStatusCounts = [];
        foreach ($companies as $company) {
            foreach ($statuses as $status) {
                $companyStatusCounts[$company][$status] = 0;
            }
        }

        foreach ($companyStatusRows as $row) {
            $companyStatusCounts[$row->company][$row->status] = (int) $row->total;
        }

        $projectCounts = MachineAssignment::query()
            ->whereNull('time_out')
            ->selectRaw('project_id, count(*) total')
            ->groupBy('project_id')
            ->with('project:id,name')
            ->orderByDesc('total')
            ->get();

        $machineExpiredCount = $this->machineExpiryQuery($today)->count();
        $machineExpiringCount = $this->machineExpiringQuery($today, $limitDate)->count();
        $driverExpiredCount = $this->driverExpiryQuery($today)->count();
        $driverExpiringCount = $this->driverExpiringQuery($today, $limitDate)->count();

        $machineExpiryDocs = MachineDocument::query()
            ->whereIn('doc_type', self::MACHINE_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('machine:id,asset_code')
            ->get()
            ->map(fn (MachineDocument $document) => [
                'type' => 'machine',
                'label' => $document->machine?->asset_code ?? '-',
                'doc_type' => $document->doc_type,
                'expiry_date' => $document->expiry_date,
                'machine_id' => $document->machine_id,
                'driver_id' => null,
            ]);

        $driverExpiryDocs = DriverDocument::query()
            ->whereIn('doc_type', self::DRIVER_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('driver:id,name')
            ->get()
            ->map(fn (DriverDocument $document) => [
                'type' => 'driver',
                'label' => $document->driver?->name ?? '-',
                'doc_type' => $document->doc_type,
                'expiry_date' => $document->expiry_date,
                'machine_id' => null,
                'driver_id' => $document->driver_id,
            ]);

        $expiryItems = $this->buildExpiryItems($machineExpiryDocs, $driverExpiryDocs)
            ->sortBy('expiry_date')
            ->take(8)
            ->values();

        $returnedNotSyncedCount = Schema::hasColumn('machines', 'returned_to_app')
            ? Machine::query()->where('status', 'RETURNED')->where('returned_to_app', false)->count()
            : 0;

        $missingGpsCount = Schema::hasColumn('machines', 'gps_file_added')
            ? Machine::query()->where('gps_file_added', false)->where('status', '!=', 'RETURNED')->count()
            : 0;

        $recentActivities = $this->recentActivities();
        $maxProjectCount = max(1, (int) ($projectCounts->max('total') ?? 1));

        return view('dashboard', compact(
            'totalMachines',
            'statuses',
            'companies',
            'statusCounts',
            'companyStatusCounts',
            'projectCounts',
            'maxProjectCount',
            'machineExpiredCount',
            'machineExpiringCount',
            'driverExpiredCount',
            'driverExpiringCount',
            'returnedNotSyncedCount',
            'missingGpsCount',
            'expiryItems',
            'recentActivities'
        ));
    }

    private function machineExpiryQuery(CarbonImmutable $today)
    {
        return MachineDocument::query()
            ->whereIn('doc_type', self::MACHINE_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today);
    }

    private function machineExpiringQuery(CarbonImmutable $today, CarbonImmutable $limitDate)
    {
        return MachineDocument::query()
            ->whereIn('doc_type', self::MACHINE_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $limitDate);
    }

    private function driverExpiryQuery(CarbonImmutable $today)
    {
        return DriverDocument::query()
            ->whereIn('doc_type', self::DRIVER_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today);
    }

    private function driverExpiringQuery(CarbonImmutable $today, CarbonImmutable $limitDate)
    {
        return DriverDocument::query()
            ->whereIn('doc_type', self::DRIVER_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $limitDate);
    }

    private function recentActivities(): Collection
    {
        if (!Schema::hasTable('machine_events')) {
            return collect();
        }

        return DB::table('machine_events')
            ->leftJoin('machines', 'machines.id', '=', 'machine_events.machine_id')
            ->select([
                'machine_events.id',
                'machine_events.type',
                'machine_events.occurred_at',
                'machines.id as machine_id',
                'machines.asset_code',
            ])
            ->orderByDesc('machine_events.occurred_at')
            ->limit(8)
            ->get()
            ->map(function ($event) {
                $meta = match ($event->type) {
                    'HANDOVER' => ['label' => 'Bàn giao', 'tone' => 'primary', 'icon' => '↗'],
                    'ACTIVATE' => ['label' => 'Kích hoạt', 'tone' => 'success', 'icon' => '✓'],
                    'TRANSFER' => ['label' => 'Điều chuyển', 'tone' => 'warning', 'icon' => '⇄'],
                    'RETURN' => ['label' => 'Trả máy', 'tone' => 'danger', 'icon' => '↙'],
                    'ASSIGN_DRIVER' => ['label' => 'Gán tài xế', 'tone' => 'info', 'icon' => '⌁'],
                    default => ['label' => $event->type, 'tone' => 'neutral', 'icon' => '•'],
                };

                return [
                    'machine_id' => $event->machine_id,
                    'asset_code' => $event->asset_code ?? '-',
                    'occurred_at' => $event->occurred_at,
                    ...$meta,
                ];
            });
    }

    private function buildExpiryItems(Collection $machineItems, Collection $driverItems): Collection
    {
        return $machineItems->merge($driverItems)->map(function (array $item) {
            $expiryDate = CarbonImmutable::parse($item['expiry_date']);

            return array_merge($item, [
                'days_diff' => CarbonImmutable::today()->diffInDays($expiryDate, false),
            ]);
        });
    }
}
