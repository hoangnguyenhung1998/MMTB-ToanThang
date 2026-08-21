<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexOcrReviewsRequest;
use App\Models\OcrJob;
use App\Services\OcrReviewService;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OcrReviewController extends Controller
{
    public function __construct(private readonly OcrReviewService $service)
    {
    }

    public function index(IndexOcrReviewsRequest $request): View
    {
        $filters = $request->validated();

        return view('ocr-reviews.index', [
            'jobs' => $this->service->paginate($filters),
            'statusCounts' => $this->service->statusCounts(),
            'machines' => $this->service->machineOptions(),
            'filters' => $filters,
        ]);
    }

    public function show(OcrJob $ocrJob): View
    {
        $job = $this->service->detail($ocrJob);

        return view('ocr-reviews.show', [
            'job' => $job,
            'imageExists' => $this->service->imageExists($job),
            'exceptionLabels' => $this->service->exceptionLabels(),
        ]);
    }

    public function image(OcrJob $ocrJob): StreamedResponse
    {
        $job = $this->service->detail($ocrJob);
        abort_unless($this->service->imageExists($job), 404);

        $attachment = $job->attachment;

        return Storage::disk($attachment->storage_disk)->response(
            $attachment->storage_path,
            $attachment->original_name ?: basename($attachment->storage_path),
            ['Content-Type' => $attachment->mime_type],
            'inline',
        );
    }
}
