<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimOpenClawCommandRequest;
use App\Http\Requests\CompleteOpenClawCommandRequest;
use App\Http\Requests\FailOpenClawCommandRequest;
use App\Models\OpenClawCommand;
use App\Services\OpenClawCommandService;
use Illuminate\Http\JsonResponse;

class OpenClawCommandController extends Controller
{
    public function __construct(private readonly OpenClawCommandService $commands)
    {
    }

    public function claim(ClaimOpenClawCommandRequest $request): JsonResponse
    {
        $data = $request->validated();
        $commands = $this->commands->claim($data['worker_id'], $data['limit'] ?? 3);

        return response()->json([
            'commands' => $commands->map(fn (OpenClawCommand $command) => $this->commands->payload($command))->all(),
        ]);
    }

    public function complete(
        CompleteOpenClawCommandRequest $request,
        OpenClawCommand $openClawCommand,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json([
            'command' => $this->commands->complete($openClawCommand, $data['worker_id'], $data),
        ]);
    }

    public function fail(
        FailOpenClawCommandRequest $request,
        OpenClawCommand $openClawCommand,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json([
            'command' => $this->commands->fail(
                $openClawCommand,
                $data['worker_id'],
                $data['error'],
                $data['retryable'],
            ),
        ]);
    }
}
