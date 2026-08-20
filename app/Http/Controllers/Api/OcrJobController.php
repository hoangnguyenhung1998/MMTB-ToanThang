<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassifyOcrJobRequest;
use App\Http\Requests\ClaimOcrJobRequest;
use App\Http\Requests\CompleteJournalOcrJobRequest;
use App\Http\Requests\CompleteOcrJobRequest;
use App\Http\Requests\FailOcrJobRequest;
use App\Models\OcrJob;
use App\Services\OcrJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OcrJobController extends Controller
{
    public function __construct(private readonly OcrJobService $service)
    {
    }

    public function claim(ClaimOcrJobRequest $request): JsonResponse
    {
        $job = $this->service->claim(
            $request->validated('worker_id'),
            $request->validated('document_types', []),
        );

        if (! $job) {
            return response()->json(null, 204);
        }

        return response()->json([
            'job' => [
                'id' => $job->id,
                'document_type' => $job->document_type,
                'attempts' => $job->attempts,
                'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
                'image_url' => route('api.ocr.jobs.image', [
                    'ocrJob' => $job,
                    'worker_id' => $request->validated('worker_id'),
                ], false),
                'message' => [
                    'group_id' => $job->attachment->message->group_id,
                    'message_id' => $job->attachment->message->message_id,
                    'sender_id' => $job->attachment->message->sender_id,
                    'sender_name' => $job->attachment->message->sender_name,
                    'sent_at' => $job->attachment->message->sent_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    public function image(Request $request, OcrJob $ocrJob): StreamedResponse
    {
        $request->validate(['worker_id' => ['required', 'string', 'max:100']]);
        $this->service->ensureClaimOwner($ocrJob, (string) $request->query('worker_id'));

        $attachment = $ocrJob->attachment;

        abort_unless(Storage::disk($attachment->storage_disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->storage_disk)->download(
            $attachment->storage_path,
            $attachment->original_name ?: basename($attachment->storage_path),
            ['Content-Type' => $attachment->mime_type],
        );
    }

    public function complete(CompleteOcrJobRequest $request, OcrJob $ocrJob): JsonResponse
    {
        $job = $this->service->complete($ocrJob->load('attachment.message'), $request->validated());

        return response()->json(['job' => $job]);
    }

    public function classify(ClassifyOcrJobRequest $request, OcrJob $ocrJob): JsonResponse
    {
        return response()->json([
            'job' => $this->service->classify($ocrJob, $request->validated()),
        ]);
    }

    public function completeJournal(
        CompleteJournalOcrJobRequest $request,
        OcrJob $ocrJob,
    ): JsonResponse {
        $job = $this->service->completeJournal(
            $ocrJob->load('attachment.message'),
            $request->validated(),
        );

        return response()->json(['job' => $job]);
    }

    public function fail(FailOcrJobRequest $request, OcrJob $ocrJob): JsonResponse
    {
        return response()->json(['job' => $this->service->fail($ocrJob, $request->validated())]);
    }

    public function machines(): JsonResponse
    {
        return response()->json([
            'machines' => $this->service->machineCatalog(),
        ]);
    }
}
