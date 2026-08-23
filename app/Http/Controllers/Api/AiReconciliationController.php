<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimAiReconciliationJobRequest;
use App\Http\Requests\CompleteAiReconciliationJobRequest;
use App\Http\Requests\FailAiReconciliationJobRequest;
use App\Models\AiReconciliationJob;
use App\Models\OcrJob;
use App\Services\AiReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiReconciliationController extends Controller
{
    public function __construct(private readonly AiReconciliationService $service)
    {
    }

    public function claim(ClaimAiReconciliationJobRequest $request): JsonResponse
    {
        $data = $request->validated();
        $jobs = $this->service->claim(
            $data['worker_id'],
            $data['work_date'],
            $data['limit'] ?? 5,
        );

        if ($jobs->isEmpty()) {
            return response()->json(status: 204);
        }

        return response()->json([
            'jobs' => $jobs->map(fn (AiReconciliationJob $job) => $this->service->payload($job))->all(),
        ]);
    }

    public function complete(
        CompleteAiReconciliationJobRequest $request,
        AiReconciliationJob $aiReconciliationJob,
    ): JsonResponse {
        $submission = $this->service->complete($aiReconciliationJob, $request->validated());

        return response()->json(['submission' => $submission]);
    }

    public function fail(
        FailAiReconciliationJobRequest $request,
        AiReconciliationJob $aiReconciliationJob,
    ): JsonResponse {
        return response()->json([
            'job' => $this->service->fail($aiReconciliationJob, $request->validated()),
        ]);
    }

    public function image(AiReconciliationJob $aiReconciliationJob, OcrJob $ocrJob): StreamedResponse
    {
        $sourceJob = $this->service->sourceImage($aiReconciliationJob, $ocrJob);
        $attachment = $sourceJob->attachment;

        abort_unless(Storage::disk($attachment->storage_disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->storage_disk)->response(
            $attachment->storage_path,
            $attachment->original_name ?: basename($attachment->storage_path),
            ['Content-Type' => $attachment->mime_type],
            'inline',
        );
    }
}
