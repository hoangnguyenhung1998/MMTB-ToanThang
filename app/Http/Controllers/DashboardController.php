<?php

namespace App\Http\Controllers;

use App\Models\DriverDocument;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;


class DashboardController extends Controller
{
    private const MACHINE_DOC_TYPES = ['Đăng ký', 'Đăng kiểm', 'Kiểm định'];
    private const DRIVER_DOC_TYPES = ['Thẻ ATLĐ', 'Giấy khám sức khỏe', 'Bảo hiểm tai nạn'];
    private const EXPIRY_DAYS = 30;

    public function index(): View
    {
        $statuses = ['WAIT_HANDOVER', 'HANDED_OVER', 'ACTIVE', 'RETURNED'];
        $companies = ['VINCONS', 'VINALPHA'];

        $totalMachines = Machine::count();

        $statusCounts = Machine::selectRaw('status, count(*) total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $companyStatusRows = Machine::selectRaw('company, status, count(*) total')
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
            ->get();

        $limitDate = CarbonImmutable::today()->addDays(self::EXPIRY_DAYS);

        $machineExpiryCount = MachineDocument::query()
            ->whereIn('doc_type', self::MACHINE_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->count();

        $driverExpiryCount = DriverDocument::query()
            ->whereIn('doc_type', self::DRIVER_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->count();

        $machineExpiryDocs = MachineDocument::query()
            ->whereIn('doc_type', self::MACHINE_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('machine:id,asset_code')
            ->get()
            ->map(function (MachineDocument $document) {
                return [
                    'type' => 'machine',
                    'label' => $document->machine?->asset_code ?? '-',
                    'doc_type' => $document->doc_type,
                    'expiry_date' => $document->expiry_date,
                    'machine_id' => $document->machine_id,
                    'driver_id' => null,
                ];
            });

        $driverExpiryDocs = DriverDocument::query()
            ->whereIn('doc_type', self::DRIVER_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('driver:id,name')
            ->get()
            ->map(function (DriverDocument $document) {
                return [
                    'type' => 'driver',
                    'label' => $document->driver?->name ?? '-',
                    'doc_type' => $document->doc_type,
                    'expiry_date' => $document->expiry_date,
                    'machine_id' => null,
                    'driver_id' => $document->driver_id,
                ];
            });

        $expiryItems = $this->buildExpiryItems($machineExpiryDocs, $driverExpiryDocs)
            ->sortBy('expiry_date')
            ->take(10)
            ->values();

        return view('dashboard', [
            'totalMachines' => $totalMachines,
            'statuses' => $statuses,
            'companies' => $companies,
            'statusCounts' => $statusCounts,
            'companyStatusCounts' => $companyStatusCounts,
            'projectCounts' => $projectCounts,
            'machineExpiryCount' => $machineExpiryCount,
            'driverExpiryCount' => $driverExpiryCount,
            'expiryItems' => $expiryItems,
        ]);
    }

    private function buildExpiryItems(Collection $machineItems, Collection $driverItems): Collection
    {
        $items = $machineItems->merge($driverItems)->map(function (array $item) {
            $expiryDate = CarbonImmutable::parse($item['expiry_date']);
            $daysDiff = CarbonImmutable::today()->diffInDays($expiryDate, false);

            return array_merge($item, [
                'days_diff' => $daysDiff,
            ]);
        });

        return $items;
    }
}
