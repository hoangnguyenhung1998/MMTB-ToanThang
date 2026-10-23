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

        return redirect()->route('ocr-reviews.ai-rescue.progress', $result);
    }

    public function progress(\Illuminate\Http\Request $request, \App\Models\DailyPhotoAiRescueRun $aiRun, \App\Services\DailyPhotoAiRescueRunService $runs)
    {
        abort_unless((int) $aiRun->user_id === (int) $request->user()->id, 403);
        $progress = $runs->progress($aiRun);

        return $request->expectsJson() ? response()->json($progress) : view('ocr-reviews.ai-rescue-progress', compact('progress'));
    }

    public function retry(\Illuminate\Http\Request $request, \App\Models\DailyPhotoAiRescueRun $aiRun, \App\Services\DailyPhotoAiRescueRunService $runs): RedirectResponse
    {
        abort_unless((int) $aiRun->user_id === (int) $request->user()->id, 403);
        $runs->retry($aiRun);

        return redirect()->route('ocr-reviews.ai-rescue.progress', $aiRun);
    }
}
