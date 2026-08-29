<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteMachineHandoverOcrRequest;
use App\Http\Requests\FailOcrJobRequest;
use App\Models\MachineHandoverOcrJob;
use App\Services\MachineHandoverOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MachineHandoverOcrController extends Controller
{
    public function __construct(private readonly MachineHandoverOcrService $service) {}

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate(['worker_id' => ['required', 'string', 'max:100']]);
        $job = $this->service->claim($data['worker_id']);
        if (! $job) return response()->json(null, 204);
        return response()->json(['job' => [
            'id' => $job->id, 'attempts' => $job->attempts, 'document_type' => 'HANDOVER_REPORT',
            'image_url' => route('api.ocr.handovers.image', ['machineHandoverOcrJob' => $job, 'worker_id' => $data['worker_id']], false),
            'machine' => ['id' => $job->document->handoverCase->machine->id, 'asset_code' => $job->document->handoverCase->machine->asset_code],
        ]]);
    }

    public function image(Request $request, MachineHandoverOcrJob $machineHandoverOcrJob): StreamedResponse
    {
        $data = $request->validate(['worker_id' => ['required', 'string', 'max:100']]);
        $this->service->ensureOwner($machineHandoverOcrJob, $data['worker_id']); $document = $machineHandoverOcrJob->document;
        abort_unless(Storage::disk($document->storage_disk)->exists($document->storage_path), 404);
        return Storage::disk($document->storage_disk)->download($document->storage_path, $document->original_name, ['Content-Type' => $document->mime_type]);
    }

    public function complete(CompleteMachineHandoverOcrRequest $request, MachineHandoverOcrJob $machineHandoverOcrJob): JsonResponse
    { return response()->json(['job' => $this->service->complete($machineHandoverOcrJob, $request->validated())]); }

    public function fail(FailOcrJobRequest $request, MachineHandoverOcrJob $machineHandoverOcrJob): JsonResponse
    { return response()->json(['job' => $this->service->fail($machineHandoverOcrJob, $request->validated())]); }
}
