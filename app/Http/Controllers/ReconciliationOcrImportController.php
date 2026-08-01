<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reconciliation\CancelReconciliationOcrImportRequest;
use App\Http\Requests\Reconciliation\ConfirmReconciliationOcrImportRequest;
use App\Http\Requests\Reconciliation\UploadReconciliationOcrImportRequest;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationOcrImportService;
use App\Services\Reconciliation\ReconciliationOcrImportStore;
use App\Services\Reconciliation\ReconciliationOcrPreviewService;
use App\Services\Reconciliation\ReconciliationOcrSpreadsheetParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

class ReconciliationOcrImportController extends Controller
{
    public function create(Request $request, ReconciliationPeriod $reconciliationPeriod): View
    {
        Gate::authorize('importOcr', $reconciliationPeriod);

        return view('reconciliation.ocr-imports.create', [
            'reconciliationPeriod' => $reconciliationPeriod,
        ]);
    }

    public function preview(
        UploadReconciliationOcrImportRequest $request,
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationOcrSpreadsheetParser $parser,
        ReconciliationOcrPreviewService $previewService,
        ReconciliationOcrImportStore $store,
    ): View|RedirectResponse {
        try {
            $preview = $previewService->preview(
                $reconciliationPeriod,
                $parser->parse($request->file('ocr_file'))
            );
            $fileName = $request->file('ocr_file')->getClientOriginalName();
            $preview['file_name'] = $fileName;

            $token = $store->put((int) $request->user()->id, (int) $reconciliationPeriod->id, $preview);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['ocr_file' => $exception->getMessage()])->withInput();
        }

        return view('reconciliation.ocr-imports.preview', [
            'reconciliationPeriod' => $reconciliationPeriod,
            'preview' => $preview,
            'fileName' => $fileName,
            'token' => $token,
        ]);
    }

    public function confirm(
        ConfirmReconciliationOcrImportRequest $request,
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationOcrImportStore $store,
        ReconciliationOcrImportService $importService,
    ): RedirectResponse {
        try {
            $preview = $store->get((int) $request->user()->id, (int) $reconciliationPeriod->id, $request->validated('token'));
            $summary = $importService->import($reconciliationPeriod, $preview);
            $store->forget((int) $request->user()->id, (int) $reconciliationPeriod->id, $request->validated('token'));
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('reconciliation-periods.ocr-import.create', $reconciliationPeriod)
                ->withErrors(['ocr_file' => $exception->getMessage()]);
        }

        return redirect()
            ->route('reconciliation-periods.show', $reconciliationPeriod)
            ->with('success', 'Đã nhập dữ liệu OCR.')
            ->with('ocr_import_summary', $summary);
    }

    public function cancel(
        CancelReconciliationOcrImportRequest $request,
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationOcrImportStore $store,
    ): RedirectResponse {
        $store->forget((int) $request->user()->id, (int) $reconciliationPeriod->id, $request->validated('token'));

        return redirect()
            ->route('reconciliation-periods.show', $reconciliationPeriod)
            ->with('success', 'Đã hủy nhập dữ liệu OCR.');
    }
}
