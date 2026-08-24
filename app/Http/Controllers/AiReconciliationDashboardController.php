<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexAiReconciliationDashboardRequest;
use App\Http\Requests\StoreOpenClawCommandRequest;
use App\Models\AiReconciliationJob;
use App\Services\AiReconciliationDashboardService;
use App\Services\AiReconciliationService;
use App\Services\OpenClawCommandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AiReconciliationDashboardController extends Controller
{
    public function __construct(
        private readonly AiReconciliationDashboardService $dashboard,
        private readonly AiReconciliationService $reconciliation,
        private readonly OpenClawCommandService $commands,
    ) {
    }

    public function index(IndexAiReconciliationDashboardRequest $request): View
    {
        $filters = $request->validated();

        return view('ai-reconciliation.index', [
            'jobs' => $this->dashboard->jobs($filters),
            'summary' => $this->dashboard->summary($filters),
            'machines' => $this->dashboard->machines(),
            'filters' => $filters,
        ]);
    }

    public function show(AiReconciliationJob $aiReconciliationJob): View
    {
        $aiReconciliationJob->load([
            'machine:id,asset_code,status',
            'submissions' => fn ($query) => $query->with('findings')->latest('submitted_at'),
            'commands' => fn ($query) => $query->with('user:id,name')->latest(),
        ]);

        return view('ai-reconciliation.show', [
            'job' => $aiReconciliationJob,
            'evidence' => $this->reconciliation->payload($aiReconciliationJob),
        ]);
    }

    public function storeCommand(
        StoreOpenClawCommandRequest $request,
        AiReconciliationJob $aiReconciliationJob,
    ): RedirectResponse {
        $this->commands->create($aiReconciliationJob, (int) $request->user()->id, $request->validated());

        return redirect()
            ->route('ai-reconciliation.show', $aiReconciliationJob)
            ->with('success', 'Đã gửi yêu cầu tới OpenClaw.');
    }
}
