<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteDailyPhotoAiRescueRequest;
use App\Http\Requests\FailDailyPhotoAiRescueRequest;
use App\Models\DailyPhotoAiRescueAttempt;
use App\Services\DailyPhotoAiRescueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DailyPhotoAiRescueController extends Controller
{
    public function __construct(private readonly DailyPhotoAiRescueService $service) {}

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate(['worker_id' => ['required', 'string', 'max:100']]);
        $attempt = $this->service->claim($data['worker_id']);
        if (! $attempt) {
            return response()->json(null, 204);
        }

        return response()->json(['job' => [
            'id' => $attempt->id,
            'ocr_job_id' => $attempt->ocr_job_id,
            'attempt' => $attempt->attempts,
            'attempts' => $attempt->attempts,
            'max_attempts' => max(1, (int) config('daily_photos.ai_rescue.max_attempts', 3)),
            'lease_seconds' => max(1, (int) config('daily_photos.ai_rescue.lease_seconds', 600)),
            'lease_expires_at' => $attempt->lease_expires_at?->toIso8601String(),
            'prompt_version' => $attempt->prompt_version,
            'schema_version' => $attempt->schema_version,
            'source_sha256' => $attempt->source_sha256,
            'image_url' => route('api.ocr.daily-ai-rescue.image', [
                'dailyPhotoAiRescueAttempt' => $attempt,
                'worker_id' => $data['worker_id'],
                'attempt' => $attempt->attempts,
            ], false),
        ]]);
    }

    public function image(Request $request, DailyPhotoAiRescueAttempt $dailyPhotoAiRescueAttempt): StreamedResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'string', 'max:100'],
            'attempt' => ['required', 'integer', 'min:1'],
        ]);
        $this->service->ensureOwner(
            $dailyPhotoAiRescueAttempt,
            $data['worker_id'],
            (int) $data['attempt'],
        );
        $attachment = $dailyPhotoAiRescueAttempt->attachment;
        abort_unless($attachment && hash_equals(
            (string) $dailyPhotoAiRescueAttempt->source_sha256,
            (string) $attachment->sha256,
        ), 409);
        abort_unless(Storage::disk($attachment->storage_disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->storage_disk)->download(
            $attachment->storage_path,
            $attachment->original_name ?: basename($attachment->storage_path),
            ['Content-Type' => $attachment->mime_type],
        );
    }

    public function complete(
        CompleteDailyPhotoAiRescueRequest $request,
        DailyPhotoAiRescueAttempt $dailyPhotoAiRescueAttempt,
    ): JsonResponse {
        return response()->json([
            'job' => $this->service->complete($dailyPhotoAiRescueAttempt, $request->validated()),
        ]);
    }

    public function fail(
        FailDailyPhotoAiRescueRequest $request,
        DailyPhotoAiRescueAttempt $dailyPhotoAiRescueAttempt,
    ): JsonResponse {
        return response()->json([
            'job' => $this->service->fail($dailyPhotoAiRescueAttempt, $request->validated()),
        ]);
    }
}
