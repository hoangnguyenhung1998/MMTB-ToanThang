<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkReviewOcrJobsRequest;
use App\Http\Requests\IndexOcrReviewsRequest;
use App\Http\Requests\UpdateOcrReviewRequest;
use App\Http\Requests\UpdateWeeklyJournalRequest;
use App\Models\OcrJob;
use App\Services\DailyPhotoAiRescueBatchService;
use App\Services\DailyPhotoExceptionReason;
use App\Services\OcrReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OcrReviewController extends Controller
{
    public function __construct(
        private readonly OcrReviewService $service,
        private readonly DailyPhotoAiRescueBatchService $aiRescue,
    ) {}

    public function index(IndexOcrReviewsRequest $r): View
    {
        $f = $r->validated();
        $dailyPhotos = (bool) config('daily_photos.enabled');

        return view('ocr-reviews.index', [
            'jobs' => $this->service->paginate($f),
            'statusCounts' => $dailyPhotos ? collect() : $this->service->statusCounts(),
            'reviewStatusCounts' => $dailyPhotos ? collect() : $this->service->reviewStatusCounts(),
            'dailyOverview' => $this->service->dailyOverview($f['overview_date'] ?? now()->toDateString()),
            'machines' => $this->service->machineOptions(),
            'filters' => $f,
            'exceptionReasonLabels' => DailyPhotoExceptionReason::LABELS,
            'aiRescueDashboard' => $dailyPhotos ? $this->aiRescue->dashboard() : null,
        ]);
    }

    public function show(OcrJob $ocrJob): View
    {
        $j = $this->service->detail($ocrJob);

        return view('ocr-reviews.show', ['job' => $j, 'machines' => $this->service->machineOptions(), 'imageExists' => $this->service->imageExists($j), 'exceptionLabels' => $this->service->exceptionLabels(), 'aiRescueState' => ($j->document_type === 'DAILY_TIMEMARK' || $j->aiRescueAttempts->isNotEmpty()) ? $this->aiRescue->state($j) : null]);
    }

    public function update(UpdateOcrReviewRequest $r, OcrJob $ocrJob): RedirectResponse
    {
        $data = $r->validated();
        $this->service->review($ocrJob, $data, $r->user());
        $message = $data['action'] === 'correct_and_add_case' ? 'Đã lưu hậu kiểm và thêm case OCR đã xác minh.' : 'Đã lưu hậu kiểm OCR.';

        return back()->with('success', $message);
    }

    public function bulk(BulkReviewOcrJobsRequest $r): RedirectResponse
    {
        $n = $this->service->bulkReview($r->validated(), $r->user());

        return back()->with('success', "Đã cập nhật {$n} job.");
    }

    public function updateJournal(UpdateWeeklyJournalRequest $r, OcrJob $ocrJob): RedirectResponse
    {
        $this->service->updateJournal($ocrJob, $r->validated(), $r->user());

        return back()->with('success', 'Đã lưu nhật trình tuần.');
    }

    public function image(OcrJob $ocrJob): StreamedResponse
    {
        $j = $this->service->detail($ocrJob);
        abort_unless($this->service->imageExists($j), 404);
        $a = $j->attachment;

        return Storage::disk($a->storage_disk)->response($a->storage_path, $a->original_name ?: basename($a->storage_path), ['Content-Type' => $a->mime_type], 'inline');
    }
}
