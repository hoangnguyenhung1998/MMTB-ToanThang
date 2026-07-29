<?php

namespace App\Http\Controllers;

use App\Models\DriverDocument;
use App\Models\MachineDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExpiryController extends Controller
{
    private const MACHINE_DOC_TYPES = ['Đăng ký', 'Đăng kiểm', 'Kiểm định'];
    private const DRIVER_DOC_TYPES = ['Thẻ ATLĐ', 'Giấy khám sức khỏe', 'Bảo hiểm tai nạn'];
    private const ALLOWED_DAYS = [7, 15, 30, 60];

    public function index(Request $request): View
    {
        $days = (int) $request->input('days', 30);
        if (!in_array($days, self::ALLOWED_DAYS, true)) {
            $days = 30;
        }

        $tab = $request->string('tab')->toString();
        if (!in_array($tab, ['machines', 'drivers'], true)) {
            $tab = 'machines';
        }

        $machineCode = $request->string('machine_code')->toString();
        $machineDocType = $request->string('machine_doc_type')->toString();
        $driverName = $request->string('driver_name')->toString();
        $driverDocType = $request->string('driver_doc_type')->toString();

        $limitDate = CarbonImmutable::today()->addDays($days);

        $machineDocumentsQuery = MachineDocument::query()
            ->whereIn('doc_type', self::MACHINE_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('machine:id,asset_code');

        if ($machineCode !== '') {
            $machineDocumentsQuery->whereHas('machine', function ($query) use ($machineCode) {
                $query->where('asset_code', 'like', '%' . $machineCode . '%');
            });
        }

        if ($machineDocType !== '' && in_array($machineDocType, self::MACHINE_DOC_TYPES, true)) {
            $machineDocumentsQuery->where('doc_type', $machineDocType);
        }

        $driverDocumentsQuery = DriverDocument::query()
            ->whereIn('doc_type', self::DRIVER_DOC_TYPES)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limitDate)
            ->with('driver:id,name');

        if ($driverName !== '') {
            $driverDocumentsQuery->whereHas('driver', function ($query) use ($driverName) {
                $query->where('name', 'like', '%' . $driverName . '%');
            });
        }

        if ($driverDocType !== '' && in_array($driverDocType, self::DRIVER_DOC_TYPES, true)) {
            $driverDocumentsQuery->where('doc_type', $driverDocType);
        }

        $machineDocuments = $machineDocumentsQuery
            ->orderBy('expiry_date')
            ->paginate(20)
            ->withQueryString()
            ->through(function ($document) {
                $expiryDate = CarbonImmutable::parse($document->expiry_date);
                $document->days_diff = CarbonImmutable::today()->diffInDays($expiryDate, false);

                return $document;
            });

        $driverDocuments = $driverDocumentsQuery
            ->orderBy('expiry_date')
            ->paginate(20)
            ->withQueryString()
            ->through(function ($document) {
                $expiryDate = CarbonImmutable::parse($document->expiry_date);
                $document->days_diff = CarbonImmutable::today()->diffInDays($expiryDate, false);

                return $document;
            });

        return view('expiries.index', [
            'days' => $days,
            'allowedDays' => self::ALLOWED_DAYS,
            'tab' => $tab,
            'machineDocTypes' => self::MACHINE_DOC_TYPES,
            'driverDocTypes' => self::DRIVER_DOC_TYPES,
            'filters' => [
                'machine_code' => $machineCode,
                'machine_doc_type' => $machineDocType,
                'driver_name' => $driverName,
                'driver_doc_type' => $driverDocType,
            ],
            'machineDocuments' => $machineDocuments,
            'driverDocuments' => $driverDocuments,
        ]);
    }

}
