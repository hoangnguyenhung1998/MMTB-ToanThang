<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reconciliation\BulkConfirmReconciliationRowsRequest;
use App\Http\Requests\Reconciliation\BulkUpdateReconciliationRowsRequest;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationMachineReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReconciliationMachineReviewController extends Controller
{
    public function show(Request $request, ReconciliationPeriod $reconciliationPeriod, ReconciliationMachineReviewService $service): View
    {
        Gate::authorize('reviewMonthly', $reconciliationPeriod);

        return view('reconciliation.periods.machine-review', array_merge(
            ['reconciliationPeriod' => $reconciliationPeriod],
            $service->overview($reconciliationPeriod, $request->integer('machine_id') ?: null, $request->query())
        ));
    }

    public function bulkUpdate(BulkUpdateReconciliationRowsRequest $request, ReconciliationPeriod $reconciliationPeriod, ReconciliationMachineReviewService $service): RedirectResponse
    {
        $summary = $service->bulkUpdate(
            $reconciliationPeriod,
            (int) $request->validated('machine_id'),
            $request->validated('rows'),
            (int) $request->user()->id,
        );

        return redirect()
            ->route('reconciliation-periods.machine-review', [
                'reconciliationPeriod' => $reconciliationPeriod,
                'machine_id' => $request->validated('machine_id'),
            ])
            ->with('success', "Đã lưu {$summary['updated']} dòng, bỏ qua {$summary['skipped']} dòng.");
    }

    public function bulkConfirm(BulkConfirmReconciliationRowsRequest $request, ReconciliationPeriod $reconciliationPeriod, ReconciliationMachineReviewService $service): RedirectResponse
    {
        $summary = $service->bulkConfirm(
            $reconciliationPeriod,
            (int) $request->validated('machine_id'),
            $request->validated('row_ids'),
            (int) $request->user()->id,
        );

        return redirect()
            ->route('reconciliation-periods.machine-review', [
                'reconciliationPeriod' => $reconciliationPeriod,
                'machine_id' => $request->validated('machine_id'),
            ])
            ->with('success', "Đã xác nhận {$summary['confirmed']} dòng, bỏ qua {$summary['skipped']} dòng.");
    }
}
