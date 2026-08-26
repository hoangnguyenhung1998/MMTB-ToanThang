<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimAutomationOperationalCommandRequest;
use App\Http\Requests\CompleteAutomationOperationalCommandRequest;
use App\Http\Requests\FailAutomationOperationalCommandRequest;
use App\Models\AutomationNode;
use App\Models\AutomationOperationalCommand;
use App\Services\AutomationOperationalCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationOperationalCommandController extends Controller
{
    public function __construct(private readonly AutomationOperationalCommandService $commands) {}

    public function claim(ClaimAutomationOperationalCommandRequest $request): JsonResponse
    {
        $node = $request->attributes->get('automation_node'); $data = $request->validated();
        $commands = $this->commands->claim($node, $data['agent_id'], $data['limit'] ?? 5);
        return response()->json(['commands' => $commands->map(fn ($command) => [
            'id' => $command->id, 'action' => $command->action,
            'service' => ['service_key' => $command->service->service_key, 'service_type' => $command->service->service_type],
        ])->values()]);
    }

    public function complete(CompleteAutomationOperationalCommandRequest $request, AutomationOperationalCommand $command): JsonResponse
    {
        return response()->json(['command' => $this->commands->complete($command, $request->attributes->get('automation_node'), $request->validated('result') ?? [])]);
    }

    public function fail(FailAutomationOperationalCommandRequest $request, AutomationOperationalCommand $command): JsonResponse
    {
        return response()->json(['command' => $this->commands->fail($command, $request->attributes->get('automation_node'), $request->validated('error'))]);
    }
}
