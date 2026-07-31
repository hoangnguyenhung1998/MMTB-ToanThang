<?php

namespace App\Http\Controllers;

use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReconciliationRowController extends Controller
{
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
        ]);
    }
}
