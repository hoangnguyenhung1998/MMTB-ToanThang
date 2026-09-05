<?php

namespace App\Http\Controllers;

use App\Exports\ReconciliationBchWorkbookExport;
use App\Http\Requests\Reconciliation\AllocateReconciliationTimesRequest;
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
use App\Models\OcrJob;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationBchZipService;
use App\Services\Reconciliation\ReconciliationCalculator;
use App\Services\Reconciliation\ReconciliationExportValidator;
use App\Services\Reconciliation\ReconciliationPeriodService;
use App\Services\Reconciliation\ReconciliationEvidenceSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ReconciliationPeriodController extends Controller
{
    public function __construct(
        private readonly ReconciliationPeriodService $periodService,
        private readonly ReconciliationCalculator $calculator
    ) {
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

    public function appendMachines(ReconciliationPeriod $reconciliationPeriod): RedirectResponse
    {
        Gate::authorize('appendMachines', $reconciliationPeriod);
        try {
            $before = $reconciliationPeriod->rows()->count();
            $period = $this->periodService->syncMonthly($reconciliationPeriod);
            $added = $period->rows()->count() - $before;
            return back()->with('success', "Đã bổ sung {$added} dòng còn thiếu; giữ nguyên các dòng hiện có.");
        } catch (Throwable $exception) {
            report($exception);
            return back()->with('error', $exception->getMessage());
        }
    }

    public function repairLinks(ReconciliationPeriod $reconciliationPeriod, \App\Services\Reconciliation\ReconciliationLinkRepairService $repair): RedirectResponse
    {
        Gate::authorize('appendMachines', $reconciliationPeriod);
        try {
            $result = $repair->repair($reconciliationPeriod, auth()->id());
            return back()->with('success', "Đã khôi phục {$result['repaired']} dòng; còn {$result['unresolved']} dòng cần kiểm tra phân công nguồn hoặc trạng thái duyệt. Không thay đổi giờ làm.");
        } catch (Throwable $exception) {
            report($exception);
            return back()->with('error', $exception->getMessage());
        }
    }

    public function store(StoreReconciliationPeriodRequest $request): RedirectResponse
    {
        try {
            $period = $this->periodService->create(
                $request->validated(),
                $request->user()?->id
            );

            return redirect()
                ->route('reconciliation-periods.show', $period)
                ->with('success', 'Đã tạo kỳ đối chiếu. Anh có thể kiểm tra thông tin rồi bấm “Sinh dữ liệu”.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage())->withInput();
        }
    }

    public function show(
        ShowReconciliationPeriodRequest $request,
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationExportValidator $exportValidator
    ): View
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
            ->when(!empty($filters['date_from']), fn ($query) => $query->whereDate('work_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($query) => $query->whereDate('work_date', '<=', $filters['date_to']))
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
            ->orderBy('work_date')
            ->orderBy('segment_start');

        $machinePager = (clone $rowsQuery)
            ->reorder()
            ->join('machines', 'machines.id', '=', 'reconciliation_rows.machine_id')
            ->select([
                'reconciliation_rows.machine_id',
                'machines.asset_code as machine_code',
            ])
            ->distinct()
            ->orderBy('machines.asset_code')
            ->paginate(1, ['*'], 'machine_page')
            ->withQueryString();

        $currentMachineId = $machinePager->first()?->machine_id;
        $rows = (clone $rowsQuery)
            ->when($currentMachineId, fn ($query) => $query->where('reconciliation_rows.machine_id', $currentMachineId))
            ->get();
        $rowCalculations = $rows->mapWithKeys(
            fn ($row) => [$row->id => $this->calculator->summaryFor($row)]
        );
        $dailyTimesByJob = OcrJob::query()
            ->whereIn('id', $rows->pluck('daily_ocr_job_ids')->flatten()->filter()->unique())
            ->pluck('extracted_time', 'id');
        $dailyEvidenceTimes = $rows->mapWithKeys(fn ($row) => [
            $row->id => collect($row->daily_ocr_job_ids ?? [])
                ->map(fn ($jobId) => $dailyTimesByJob->get($jobId))
                ->filter()
                ->map(fn ($time) => substr((string) $time, 0, 5))
                ->unique()
                ->sort()
                ->values(),
        ]);

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
        $exportable = in_array($reconciliationPeriod->status, ['GENERATED', 'REVIEWING', 'CONFIRMED', 'EXPORTED'], true);
        $exportValidation = $exportValidator->validate($reconciliationPeriod);
        $canConfirmPeriod = $reconciliationPeriod->status === 'REVIEWING'
            && $totalRowsForConfirmation > 0
            && $confirmedCount === $totalRowsForConfirmation
            && $rejectedCount === 0;

        return view('reconciliation.periods.show', compact(
            'reconciliationPeriod',
            'rowSummary',
            'changeSummary',
            'rows',
            'machinePager',
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
            'rowCalculations',
            'dailyEvidenceTimes',
            'exportable',
            'exportValidation',
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

    public function allocateTimes(
        AllocateReconciliationTimesRequest $request,
        ReconciliationPeriod $reconciliationPeriod,
        ReconciliationEvidenceSyncService $evidenceSyncService
    ): RedirectResponse {
        try {
            $result = $evidenceSyncService->sync($reconciliationPeriod);

            return back()->with('success', "Đã đồng bộ {$result['updated']} dòng; bảo vệ {$result['protected']} dòng đã sửa hoặc xác nhận.");
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
        ReconciliationExportValidator $exportValidator,
        ReconciliationBchZipService $zipService
    ): BinaryFileResponse {
        $validation = $exportValidator->validate($reconciliationPeriod);
        abort_unless($validation['can_export'], 422, 'Chưa thể xuất: kỳ đối chiếu còn lỗi bắt buộc phải sửa.');
        abort_if(
            $validation['warnings']->isNotEmpty() && !$request->boolean('acknowledge_warnings'),
            422,
            'Kỳ đối chiếu còn cảnh báo. Hãy kiểm tra và xác nhận vẫn xuất.'
        );

        $filters = $request->safe()->only([
            'machine_id', 'project_id', 'command_center_id', 'date_from', 'date_to',
        ]);
        $hasRows = $reconciliationPeriod->rows()
            ->whereNotNull('command_center_id')
            ->when($filters['machine_id'] ?? null, fn ($query, $id) => $query->where('machine_id', $id))
            ->when($filters['project_id'] ?? null, fn ($query, $id) => $query->where('project_id', $id))
            ->when($filters['command_center_id'] ?? null, fn ($query, $id) => $query->where('command_center_id', $id))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('work_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('work_date', '<=', $date))
            ->exists();
        abort_unless($hasRows, 422, 'Không có dữ liệu phù hợp với bộ lọc xuất Excel.');

        if ($request->input('mode', 'workbook') === 'zip') {
            try {
                $zipPath = $zipService->create($reconciliationPeriod, $filters);
            } catch (Throwable $exception) {
                report($exception);
                abort(422, $exception->getMessage());
            }
            $prefix = in_array($reconciliationPeriod->status, ['CONFIRMED', 'EXPORTED'], true)
                ? 'doi-chieu-tung-bch-'
                : 'doi-chieu-nhap-tung-bch-';
            $zipName = $prefix.$reconciliationPeriod->date_from->format('Y-m').'.zip';

            return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
        }

        $prefix = in_array($reconciliationPeriod->status, ['CONFIRMED', 'EXPORTED'], true)
            ? 'doi-chieu-bch-'
            : 'doi-chieu-nhap-bch-';
        $filename = $prefix.$reconciliationPeriod->date_from->format('Y-m').'-'.now()->format('YmdHis').'.xlsx';

        return Excel::download(
            new ReconciliationBchWorkbookExport($reconciliationPeriod, $filters),
            $filename
        );
    }
}
