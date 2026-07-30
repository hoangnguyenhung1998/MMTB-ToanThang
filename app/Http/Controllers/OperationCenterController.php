<?php

namespace App\Http\Controllers;

use App\Models\DriverDocument;
use App\Models\Machine;
use App\Models\MachineDocument;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class OperationCenterController extends Controller
{
    private const EXPIRY_DAYS = 30;
    private const LIST_LIMIT = 20;

    public function index(): View
    {
        $today = CarbonImmutable::today();
        $limitDate = $today->addDays(self::EXPIRY_DAYS);

        $waitingHandoverQuery = Machine::query()
            ->where('status', 'WAIT_HANDOVER');

        $returnedNotSyncedQuery = Machine::query()
            ->where('status', 'RETURNED')
            ->where(function ($query) {
                $query->whereNull('returned_to_app')
                    ->orWhere('returned_to_app', false);
            });

        $missingGpsQuery = Machine::query()
            ->where('status', '!=', 'RETURNED')
            ->where(function ($query) {
                $query->whereNull('gps_file_added')
                    ->orWhere('gps_file_added', false);
            });

        $missingDriverQuery = Machine::query()
            ->whereIn('status', ['HANDED_OVER', 'ACTIVE'])
            ->whereNull('current_driver_id');

        $waitingHandover = (clone $waitingHandoverQuery)
            ->orderBy('asset_code')
            ->limit(self::LIST_LIMIT)
            ->get();

        $returnedNotSynced = (clone $returnedNotSyncedQuery)
            ->orderBy('asset_code')
            ->limit(self::LIST_LIMIT)
            ->get();

        $missingGps = (clone $missingGpsQuery)
            ->orderBy('asset_code')
            ->limit(self::LIST_LIMIT)
            ->get();

        $missingDriver = (clone $missingDriverQuery)
            ->orderBy('asset_code')
            ->limit(self::LIST_LIMIT)
            ->get();

        $machineExpiryDocs = MachineDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('machine:id,asset_code')
            ->orderBy('expiry_date')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function (MachineDocument $document) use ($today) {
                $expiryDate = CarbonImmutable::parse($document->expiry_date);

                return [
                    'owner_type' => 'machine',
                    'owner_id' => $document->machine_id,
                    'owner_label' => $document->machine?->asset_code ?? '-',
                    'doc_type' => $document->doc_type,
                    'expiry_date' => $expiryDate,
                    'days_diff' => $today->diffInDays($expiryDate, false),
                ];
            });

        $driverExpiryDocs = DriverDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('driver:id,name')
            ->orderBy('expiry_date')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function (DriverDocument $document) use ($today) {
                $expiryDate = CarbonImmutable::parse($document->expiry_date);

                return [
                    'owner_type' => 'driver',
                    'owner_id' => $document->driver_id,
                    'owner_label' => $document->driver?->name ?? '-',
                    'doc_type' => $document->doc_type,
                    'expiry_date' => $expiryDate,
                    'days_diff' => $today->diffInDays($expiryDate, false),
                ];
            });

        $expiryItems = $machineExpiryDocs
            ->merge($driverExpiryDocs)
            ->sortBy('expiry_date')
            ->take(self::LIST_LIMIT)
            ->values();

        $expiredDocuments = $expiryItems
            ->filter(fn (array $item) => $item['days_diff'] < 0)
            ->count();

        $expiringDocuments = $expiryItems
            ->filter(fn (array $item) => $item['days_diff'] >= 0)
            ->count();

        $counts = [
            'waiting_handover' => (clone $waitingHandoverQuery)->count(),
            'returned_not_synced' => (clone $returnedNotSyncedQuery)->count(),
            'missing_gps' => (clone $missingGpsQuery)->count(),
            'missing_driver' => (clone $missingDriverQuery)->count(),
            'expired_documents' => $expiredDocuments,
            'expiring_documents' => $expiringDocuments,
        ];

        $counts['total'] = array_sum($counts);

        return view('operation-center.index', compact(
            'counts',
            'waitingHandover',
            'returnedNotSynced',
            'missingGps',
            'missingDriver',
            'expiryItems'
        ));
    }
}
