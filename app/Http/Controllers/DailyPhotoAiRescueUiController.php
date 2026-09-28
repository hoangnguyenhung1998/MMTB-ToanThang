<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExecuteDailyPhotoAiRescueRequest;
use App\Http\Requests\PreviewDailyPhotoAiRescueRequest;
use App\Models\OcrJob;
use App\Services\DailyPhotoAiRescueBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DailyPhotoAiRescueUiController extends Controller
{
    public function __construct(private readonly DailyPhotoAiRescueBatchService $service) {}

    public function requestSingle(OcrJob $ocrJob): RedirectResponse
    {
        $result = $this->service->requestSingle($ocrJob, request()->user());
        $message = $result['created']
            ? 'Đã đưa ảnh vào hàng đợi AI Rescue.'
            : 'Ảnh đã có AI Rescue đang chờ hoặc đang xử lý.';

        return back()->with('success', $message);
    }

    public function preview(PreviewDailyPhotoAiRescueRequest $request): View
    {
        return view('ocr-reviews.ai-rescue-preview', [
            'preview' => $this->service->preview($request->validated('reason_groups')),
        ]);
    }

    public function selectionCount(PreviewDailyPhotoAiRescueRequest $request): JsonResponse
    {
        return response()->json([
            'unique_photos' => $this->service->selectionCount($request->validated('reason_groups')),
        ]);
    }

    public function execute(ExecuteDailyPhotoAiRescueRequest $request): RedirectResponse
    {
        $result = $this->service->execute($request->validated('preview_token'), $request->user());

        return redirect()->route('ocr-reviews.index')->with(
            'success',
            "AI Rescue đã tiếp nhận {$result['accepted']} ảnh; bỏ qua {$result['skipped']} ảnh sau khi kiểm tra lại.",
        );
    }
}
