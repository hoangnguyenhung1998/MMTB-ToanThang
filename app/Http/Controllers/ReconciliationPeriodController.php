<?php

namespace App\Http\Controllers;

use App\Exports\ReconciliationRowsExport;
use App\Http\Requests\Reconciliation\ConfirmReconciliationPeriodRequest;
use App\Http\Requests\Reconciliation\DestroyReconciliationPeriodRequest;
use App\Http\Requests\Reconciliation\ExportReconciliationPeriodRequest;
use App\Http\Requests\Reconciliation\GenerateReconciliationPeriodRequest;
use App\Http\Requests\Reconciliation\IndexReconciliationPeriodsRequest;
use App\Http\Requests\Reconciliation\LockReconciliationPeriodRequest;
use App\Http\Requests\Reconciliation\ShowReconciliationPeriodRequest;
use App\Http\Requests\Reconciliation\StartReviewReconciliationPeriodRequest;
use App\Http\Requests\Reconciliation\StoreReconciliationPeriodRequest;
use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationCalculator;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ReconciliationPeriodController extends Controller
{
    public function __construct(private readonly ReconciliationPeriodService $periodService)
    {
    }

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
        $period = $this->periodService->create(
            $request->validated(),
            $request->user()?->id
        );

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
            ->when(!empty($filters['q']), function ($query) use ($filters) {
                $keyword = '%'.$filters['q'].'%';

                $query->where(function ($inner) use ($keyword) {
                    $inner->where('work_location', 'like', $keyword)
                        ->orWhere('work_content', 'like', $keyword)
                        ->orWhere('explanation', 'like', $keyword)
                        ->orWhere('notes', 'like', $keyword)
                        ->orWhereHas('machine', fn ($machineQuery) => $machineQuery->where('asset_code', 'like', $keyword))
                        ->orWhereHas('driver', fn ($driverQuery) => $driverQuery->where('name', 'like', $keyword))
                        ->orWhereHas('project', fn ($projectQuery) => $projectQuery->where('name', 'like', $keyword))
                        ->orWhereHas('commandCenter', fn ($centerQuery) => $centerQuery->where('name', 'like', $keyword));
                });
            })
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
        $confirmedCount = $reconciliationPeriod->rows()->where('status', 'CONFIRMED')->count();
        $changedCount = $reconciliationPeriod->rows()->whereNotNull('change_type')->count();
        $rejectedCount = $reconciliationPeriod->rows()->where('status', 'REJECTED')->count();
        $draftCount = $reconciliationPeriod->rows()->where('status', 'DRAFT')->count();
        $totalRowsForConfirmation = $reconciliationPeriod->rows()->count();
        $exportable = in_array($reconciliationPeriod->status, ['CONFIRMED', 'EXPORTED'], true);
        $canConfirmPeriod = $reconciliationPeriod->status === 'REVIEWING'
            && $totalRowsForConfirmation > 0
            && $confirmedCount === $totalRowsForConfirmation
            && $rejectedCount === 0;

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
            'changedCount',
            'rejectedCount',
            'draftCount',
            'exportable',
            'canConfirmPeriod'
        ));
    }

    public function generate(
        GenerateReconciliationPeriodRequest $request,
        ReconciliationPeriod $reconciliationPeriod
    ): RedirectResponse {
        try {
            $generated = $this->periodService->generate($reconciliationPeriod);

            return redirect()
                ->route('reconciliation-periods.show', $generated)
                ->with('success', 'Đã sinh dữ liệu đối chiếu thành công.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function startReview(
        StartReviewReconciliationPeriodRequest $request,
        ReconciliationPeriod $reconciliationPeriod
    ): RedirectResponse {
        try {
            $reviewing = $this->periodService->startReview($reconciliationPeriod);

            return redirect()
                ->route('reconciliation-periods.show', $reviewing)
                ->with('success', 'Đã chuyển kỳ đối chiếu sang trạng thái kiểm tra.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function destroy(
        DestroyReconciliationPeriodRequest $request,
        ReconciliationPeriod $reconciliationPeriod
    ): RedirectResponse {
        try {
            $this->periodService->deleteDraft($reconciliationPeriod);

            return redirect()
                ->route('reconciliation-periods.index')
                ->with('success', 'Đã xóa kỳ đối chiếu nháp.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function confirm(
        ConfirmReconciliationPeriodRequest $request,
        ReconciliationPeriod $reconciliationPeriod
    ): RedirectResponse {
        try {
            $confirmed = $this->periodService->confirm($reconciliationPeriod);

            return redirect()
                ->route('reconciliation-periods.show', $confirmed)
                ->with('success', 'Đã xác nhận kỳ đối chiếu.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function lock(
        LockReconciliationPeriodRequest $request,
        ReconciliationPeriod $reconciliationPeriod
    ): RedirectResponse {
        try {
            $locked = $this->periodService->lock($reconciliationPeriod);

            return redirect()
                ->route('reconciliation-periods.show', $locked)
                ->with('success', 'Đã khóa kỳ đối chiếu.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function export(
        ExportReconciliationPeriodRequest $request,
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationCalculator $calculator
    ): BinaryFileResponse {
        $filename = 'doi-chieu-'.$reconciliationPeriod->id.'-'.now()->format('YmdHis').'.xlsx';

        return Excel::download(
            new ReconciliationRowsExport($reconciliationPeriod, $calculator),
            $filename
        );
    }
}
