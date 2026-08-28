<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMachineIntakeEmailReplyRequest;
use App\Services\MachineIntakeEmailReplyService;
use Illuminate\Http\JsonResponse;

class MachineIntakeEmailReplyController extends Controller
{
    public function __construct(private readonly MachineIntakeEmailReplyService $service) {}

    public function store(StoreMachineIntakeEmailReplyRequest $request): JsonResponse
    {
        $reply = $this->service->ingest($request->validated());
        return response()->json(['reply' => [
            'id' => $reply->id,
            'status' => $reply->status,
            'case_reference' => $reply->intakeCase?->reference,
            'candidate_asset_code' => $reply->candidate_asset_code,
        ]]);
    }
}
