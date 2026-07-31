<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reconciliation\GenerateReconciliationPeriodRequest;
use App\Http\Requests\Reconciliation\IndexReconciliationPeriodsRequest;
use App\Http\Requests\Reconciliation\ShowReconciliationPeriodRequest;
use App\Http\Requests\Reconciliation\StoreReconciliationPeriodRequest;
use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class ReconciliationPeriodController extends Controller
{
    public function index(IndexReconciliationPeriodsRequest $request): View
    {
        $filters = $request->validated();

        $periods = ReconciliationPeriod::query()
            ->withCount('rows')
            ->with('creator:id,name')
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(!empty($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->latest('date_from')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('reconciliation.periods.index', compact('periods'));
    }

    public function create(): View
    {
        Gate::authorize('create', ReconciliationPeriod::class);

        return view('reconciliation.periods.create');
    }

    public function store(StoreReconciliationPeriodRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $period = ReconciliationPeriod::query()->create([
            ...$validated,
            'status' => 'DRAFT',
            'created_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('reconciliation-periods.show', $period)
            ->with('success', 'Đã tạo kỳ đối chiếu. Anh có thể kiểm tra thông tin rồi bấm “Sinh dữ liệu”.');
    }

    public function show(ShowReconciliationPeriodRequest $request, ReconciliationPeriod $reconciliationPeriod): View
    {
        $filters = $request->validated();

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
            ->when(!empty($filters['machine_id']), fn ($query) => $query->where('machine_id', (int) $filters['machine_id']))
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['command_center_id']), fn ($query) => $query->where('command_center_id', (int) $filters['command_center_id']))
            ->when(!empty($filters['work_date']), fn ($query) => $query->whereDate('work_date', $filters['work_date']))
            ->when(!empty($filters['row_status']), fn ($query) => $query->where('status', $filters['row_status']))
            ->when(!empty($filters['change_type']), fn ($query) => $query->where('change_type', $filters['change_type']))
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
        GenerateReconciliationPeriodRequest $request,
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
