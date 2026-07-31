<?php

namespace App\Http\Controllers;

use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ReconciliationPeriodController extends Controller
{
    public function index(Request $request): View
    {
        $periods = ReconciliationPeriod::query()
            ->withCount('rows')
            ->with('creator:id,name')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->latest('date_from')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('reconciliation.periods.index', compact('periods'));
    }

    public function create(): View
    {
        return view('reconciliation.periods.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:WEEKLY,MONTHLY'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $period = ReconciliationPeriod::query()->create([
            ...$validated,
            'status' => 'DRAFT',
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('reconciliation-periods.show', $period)
            ->with('success', 'Đã tạo kỳ đối chiếu. Anh có thể kiểm tra thông tin rồi bấm “Sinh dữ liệu”.');
    }

    public function show(ReconciliationPeriod $reconciliationPeriod): View
    {
        $reconciliationPeriod->loadCount('rows')->load('creator:id,name');

        $rowSummary = $reconciliationPeriod->rows()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $changeSummary = $reconciliationPeriod->rows()
            ->whereNotNull('change_type')
            ->selectRaw('change_type, COUNT(*) as total')
            ->groupBy('change_type')
            ->pluck('total', 'change_type');

        return view('reconciliation.periods.show', compact(
            'reconciliationPeriod',
            'rowSummary',
            'changeSummary'
        ));
    }

    public function generate(
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationGenerator $generator
    ): RedirectResponse {
        try {
            $generated = $generator->generate($reconciliationPeriod);

            return redirect()
                ->route('reconciliation-periods.show', $generated)
                ->with('success', 'Đã sinh dữ liệu đối chiếu thành công.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }
}
