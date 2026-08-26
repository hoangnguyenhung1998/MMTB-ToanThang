<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutomationHeartbeatRequest;
use App\Models\AutomationNode;
use App\Services\AutomationHealthService;
use Illuminate\Http\JsonResponse;

class AutomationHeartbeatController extends Controller
{
    public function __invoke(AutomationHeartbeatRequest $request, AutomationHealthService $health): JsonResponse
    {
        /** @var AutomationNode $node */
        $node = $request->attributes->get('automation_node');
        $services = $health->heartbeat($node, $request->validated());

        return response()->json([
            'node' => $node->node_key,
            'server_time' => now()->toIso8601String(),
            'services' => $services->map(fn ($service) => [
                'service_key' => $service->service_key,
                'status' => $health->statusFor($service),
            ])->values(),
        ]);
    }
}
