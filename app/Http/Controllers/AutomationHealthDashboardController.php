<?php

namespace App\Http\Controllers;

use App\Models\AutomationIncident;
use App\Models\AutomationNode;
use App\Services\AutomationHealthService;
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
        ]);
    }
}
