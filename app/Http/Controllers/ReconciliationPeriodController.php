<?php

namespace App\Http\Controllers;

use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\Project;
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

    public function show(Request $request, ReconciliationPeriod $reconciliationPeriod): View
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

        $rowsQuery = $reconciliationPeriod->rows()
            ->with([
                'machine',
                'project:id,name',
                'commandCenter:id,name',
                'driver',
                'reviewer:id,name',
                'confirmer:id,name',
            ])
            ->when($request->filled('machine_id'), fn ($query) => $query->where('machine_id', $request->integer('machine_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('command_center_id'), fn ($query) => $query->where('command_center_id', $request->integer('command_center_id')))
            ->when($request->filled('work_date'), fn ($query) => $query->whereDate('work_date', $request->date('work_date')))
            ->when($request->filled('row_status'), fn ($query) => $query->where('status', $request->string('row_status')))
            ->when($request->filled('change_type'), fn ($query) => $query->where('change_type', $request->string('change_type')))
            ->orderByDesc('work_date')
            ->orderBy('machine_id');

        $rows = $rowsQuery->paginate(50)->withQueryString();

        $machineIds = $reconciliationPeriod->rows()
            ->whereNotNull('machine_id')
            ->distinct()
            ->pluck('machine_id');

        $projectIds = $reconciliationPeriod->rows()
            ->whereNotNull('project_id')
            ->distinct()
            ->pluck('project_id');

        $commandCenterIds = $reconciliationPeriod->rows()
            ->whereNotNull('command_center_id')
            ->distinct()
            ->pluck('command_center_id');

        $machines = Machine::query()
            ->whereIn('id', $machineIds)
            ->orderBy('asset_code')
            ->get();

        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        $commandCenters = CommandCenter::query()
            ->whereIn('id', $commandCenterIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        $rowStatuses = $reconciliationPeriod->rows()
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        $changeTypes = $reconciliationPeriod->rows()
            ->whereNotNull('change_type')
            ->distinct()
            ->orderBy('change_type')
            ->pluck('change_type');

        $reviewedCount = $reconciliationPeriod->rows()->whereNotNull('reviewed_at')->count();
        $confirmedCount = $reconciliationPeriod->rows()->whereNotNull('confirmed_at')->count();
        $changedCount = $reconciliationPeriod->rows()->whereNotNull('change_type')->count();

        return view('reconciliation.periods.show', compact(
            'reconciliationPeriod',
            'rowSummary',
            'changeSummary',
            'rows',
            'machines',
            'projects',
            'commandCenters',
            'rowStatuses',
            'changeTypes',
            'reviewedCount',
            'confirmedCount',
            'changedCount'
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
