<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\OcrJob;
use App\Models\ReconciliationRow;
use App\Services\DailyPhotoWorkflowService;
use App\Services\ZaloSenderDriverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DailyPhotoController extends Controller
{
    public function requeue(Request $request, OcrJob $ocrJob, DailyPhotoWorkflowService $service)
    {
        $data = $request->validate(['document_type' => ['required', 'in:DAILY_TIMEMARK,WEEKLY_JOURNAL,UNKNOWN']]);
        $service->requeue($ocrJob, $data['document_type'], $request->user()->id);
        return back()->with('success', 'Đã lưu phân loại. Ảnh hằng ngày được xếp hàng OCR lại; kết quả cũ được lưu trong lịch sử.');
    }

    public function allocate(Request $request, ReconciliationRow $reconciliationRow, DailyPhotoWorkflowService $service)
    {
        Gate::authorize('update', $reconciliationRow);
        $data = $request->validate([
            'intervals' => ['required', 'array', 'max:5'],
            'intervals.*.kind' => ['required', 'in:'.implode(',', \App\Services\Reconciliation\DailyTimeAllocator::KINDS)],
            'intervals.*.start' => ['nullable', 'date_format:H:i'], 'intervals.*.end' => ['nullable', 'date_format:H:i'],
            'intervals.*.start_job_id' => ['nullable', 'integer'], 'intervals.*.end_job_id' => ['nullable', 'integer'],
            'manual_reason' => ['nullable', 'string', 'max:1000'], 'confirm_manual' => ['nullable', 'accepted'],
        ]);
        $service->allocate($reconciliationRow, $data, $request->user()->id);
        return back()->with('success', 'Đã làm tròn và phân bổ giờ trên một dòng.');
    }

    public function settings()
    {
        return view('daily-photos.settings', ['drivers' => Driver::orderBy('name')->get(),
            'links' => DB::table('zalo_sender_driver_links')->join('drivers', 'drivers.id', '=', 'driver_id')->select('zalo_sender_driver_links.*', 'drivers.name')->orderByDesc('id')->paginate(30),
            'senders' => DB::table('zalo_messages')->whereNotNull('sender_id')->select('sender_id', 'sender_name')->distinct()->limit(500)->get()]);
    }

    public function link(Request $request, ZaloSenderDriverService $service)
    {
        $data = $request->validate(['sender_id' => ['required', 'string', 'max:100'], 'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'valid_from' => ['required', 'date_format:Y-m-d\TH:i'], 'valid_to' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:valid_from']]);
        foreach (['valid_from', 'valid_to'] as $key) if (!empty($data[$key])) $data[$key] = str_replace('T', ' ', $data[$key]).':00';
        $service->link($data, $request->user()->id);
        return back()->with('success', 'Đã lưu ánh xạ. Dùng OCR lại với ảnh chưa xác định máy.');
    }

    public function closeLink(Request $request, int $link, ZaloSenderDriverService $service)
    {
        $data = $request->validate(['valid_to' => ['required', 'date_format:Y-m-d\TH:i']]);
        $service->close($link, str_replace('T', ' ', $data['valid_to']).':00', $request->user()->id);
        return back()->with('success', 'Đã kết thúc ánh xạ. Lịch sử được giữ nguyên.');
    }
}
