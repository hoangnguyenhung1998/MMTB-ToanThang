<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reconciliation\ConfirmReconciliationRowRequest;
use App\Http\Requests\Reconciliation\ReviewReconciliationRowRequest;
use App\Http\Requests\Reconciliation\UpdateReconciliationRowRequest;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Services\Reconciliation\ReconciliationCalculator;
use App\Services\Reconciliation\ReconciliationRowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class ReconciliationRowController extends Controller
{
    public function __construct(
        private readonly ReconciliationCalculator $calculator,
        private readonly ReconciliationRowService $rowService
    ) {
    }

    /**
     * Hiển thị chi tiết một dòng đối chiếu máy/ngày.
     */
    public function show(
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationRow $reconciliationRow
    ): View {
        abort_unless(
            $reconciliationRow->reconciliation_period_id === $reconciliationPeriod->id,
            404
        );

        Gate::authorize('view', $reconciliationRow);

        $reconciliationRow->load([
            'period.creator:id,name',
            'machine',
            'assignment',
            'project:id,name',
            'commandCenter:id,name',
            'driver',
            'reviewer:id,name',
            'confirmer:id,name',
        ]);

        return view('reconciliation.rows.show', [
            'reconciliationPeriod' => $reconciliationPeriod,
            'reconciliationRow' => $reconciliationRow,
            'calculation' => $this->calculator->summaryFor($reconciliationRow),
        ]);
    }

    public function update(
        UpdateReconciliationRowRequest $request,
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationRow $reconciliationRow
    ): RedirectResponse {
        abort_unless($reconciliationRow->reconciliation_period_id === $reconciliationPeriod->id, 404);

        try {
            $validated = $request->validated();
            $machinePage = $validated['machine_page'] ?? null;
            $submitAction = $validated['submit_action'] ?? 'save';
            $returnFilters = array_filter(
                $validated['return_filters'] ?? [],
                fn ($value) => $value !== null && $value !== ''
            );
            unset($validated['return_to'], $validated['submit_action'], $validated['machine_page'], $validated['return_filters']);
            $updatedRow = $this->rowService->update($reconciliationRow, $validated);

            if ($submitAction === 'quick_confirm') {
                $this->rowService->quickConfirm($updatedRow, (int) $request->user()->id);
            }

            if ($request->input('return_to') === 'period') {
                return redirect()
                    ->route('reconciliation-periods.show', [
                        'reconciliationPeriod' => $reconciliationPeriod,
                        ...$returnFilters,
                        'machine_page' => $machinePage,
                    ])
                    ->with('success', $submitAction === 'quick_confirm'
                        ? 'Đã lưu, duyệt và xác nhận dòng đối chiếu.'
                        : 'Đã cập nhật và tự tính lại giờ đối chiếu.');
            }

            return redirect()
                ->route('reconciliation-rows.show', [$reconciliationPeriod, $reconciliationRow])
                ->with('success', 'Đã cập nhật dòng đối chiếu. Dòng đã được đưa về trạng thái nháp.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage())->withInput();
        }
    }

    public function review(
        ReviewReconciliationRowRequest $request,
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationRow $reconciliationRow
    ): RedirectResponse {
        abort_unless($reconciliationRow->reconciliation_period_id === $reconciliationPeriod->id, 404);

        try {
            $validated = $request->validated();

            $this->rowService->review(
                $reconciliationRow,
                (int) $request->user()->id,
                $validated['decision'],
                $validated['comment'] ?? null
            );

            return redirect()
                ->route('reconciliation-rows.show', [$reconciliationPeriod, $reconciliationRow])
                ->with('success', 'Đã cập nhật kết quả kiểm tra dòng đối chiếu.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage())->withInput();
        }
    }

    public function confirm(
        ConfirmReconciliationRowRequest $request,
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationRow $reconciliationRow
    ): RedirectResponse {
        abort_unless($reconciliationRow->reconciliation_period_id === $reconciliationPeriod->id, 404);

        try {
            $this->rowService->confirm($reconciliationRow, (int) $request->user()->id);

            return redirect()
                ->route('reconciliation-rows.show', [$reconciliationPeriod, $reconciliationRow])
                ->with('success', 'Đã xác nhận dòng đối chiếu.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }
}
