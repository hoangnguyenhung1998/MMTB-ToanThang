<?php

namespace App\Http\Controllers;

use App\Models\AutomationIncident;
use App\Models\AutomationNode;
use App\Models\AutomationOperationalCommand;
use App\Models\AutomationService;
use App\Http\Requests\StoreAutomationOperationalCommandRequest;
use App\Services\AutomationHealthService;
use App\Services\AutomationOperationalCommandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AutomationHealthDashboardController extends Controller
{
    public function index(AutomationHealthService $health): View
    {
        $nodes = AutomationNode::query()
            ->with(['services' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();

        $summary = ['HEALTHY' => 0, 'DEGRADED' => 0, 'OFFLINE' => 0, 'PAUSED' => 0];
        foreach ($nodes->flatMap->services as $service) {
            $service->effective_status = $health->statusFor($service);
            $summary[$service->effective_status]++;
        }

        return view('automation-health.index', [
            'nodes' => $nodes,
            'summary' => $summary,
            'incidents' => AutomationIncident::query()
                ->with('service.node')
                ->latest('started_at')
                ->limit(30)
                ->get(),
            'commands' => AutomationOperationalCommand::query()->with(['service.node', 'user:id,name'])->latest()->limit(30)->get(),
        ]);
    }

    public function storeCommand(
        StoreAutomationOperationalCommandRequest $request,
        AutomationService $automationService,
        AutomationOperationalCommandService $commands,
    ): RedirectResponse {
        $commands->create($automationService, (int) $request->user()->id, $request->validated('action'));
        return back()->with('success', 'Đã xếp lệnh vận hành; agent sẽ nhận trong tối đa 60 giây.');
    }
}
