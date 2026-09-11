<?php

namespace App\Http\Controllers;

use App\Models\OcrJob;
use App\Models\ReconciliationRow;
use App\Services\DailyPhotoBacklogService;
use App\Services\DailyPhotoExceptionReason;
use App\Services\DailyPhotoWorkflowService;
use App\Services\ZaloSenderDriverService;
use Illuminate\Http\Request;
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

    public function settings(DailyPhotoBacklogService $backlog)
    {
        $report = $backlog->report();
        $legacy = app(\App\Services\ZaloSenderMachineService::class)->legacyCurrent()->keyBy('sender_id');
        $senders = $backlog->senderDashboard($report)->map(function (array $row) use ($legacy): array {
            if (! $row['mapping'] && $legacy->has($row['sender_id'])) {
                $row['mapping'] = $legacy->get($row['sender_id']);
                $row['legacy_mapping'] = true;
            }

            return $row;
        });

        return view('daily-photos.settings', [
            'machines' => \App\Models\Machine::orderBy('asset_code')->get(['id', 'asset_code']),
            'senders' => $senders,
            'report' => $report,
            'reasonLabels' => DailyPhotoExceptionReason::LABELS,
        ]);
    }

    public function link(Request $request, ZaloSenderDriverService $service)
    {
        if ($request->has('machine_id') || ! $request->has('driver_id')) {
            $data = $request->validate(['sender_id' => ['required', 'string', 'max:100'], 'machine_id' => ['required', 'integer', 'exists:machines,id']]);
            app(\App\Services\ZaloSenderMachineService::class)->save($data['sender_id'], (int) $data['machine_id'], $request->user()->id);

            $pending = app(DailyPhotoBacklogService::class)->report(['sender_id' => $data['sender_id']])['auto_recoverable'];

            return back()->with('success', "Đã cập nhật máy mặc định và giữ nguyên lịch sử. Có {$pending} ảnh đang chờ có thể xử lý lại; chưa ảnh nào được tự động xử lý.");
        }
        $data = $request->validate(['sender_id' => ['required', 'string', 'max:100'], 'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'valid_from' => ['required', 'date_format:Y-m-d\TH:i'], 'valid_to' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:valid_from']]);
        foreach (['valid_from', 'valid_to'] as $key) {
            if (! empty($data[$key])) {
                $data[$key] = str_replace('T', ' ', $data[$key]).':00';
            }
        }
        $service->link($data, $request->user()->id);

        return back()->with('success', 'Đã lưu ánh xạ. Dùng OCR lại với ảnh chưa xác định máy.');
    }

    public function recover(Request $request, DailyPhotoBacklogService $backlog)
    {
        $data = $request->validate([
            'sender_id' => ['required', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'reason' => ['nullable', 'in:'.implode(',', array_keys(DailyPhotoExceptionReason::LABELS))],
            'command_center_id' => ['nullable', 'integer', 'exists:command_centers,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);
        $result = $backlog->recover(array_filter($data, fn ($value) => filled($value)));

        return back()->with('success', sprintf(
            'Đã kiểm tra %d ảnh: recovered %d, xếp hàng OCR retry %d, còn exception %d, bỏ qua dữ liệu được bảo vệ %d.',
            $result['total'], $result['recovered'], $result['queued_retry'], $result['still_exception'], $result['skipped_protected'],
        ));
    }

    public function closeLink(Request $request, int $link, ZaloSenderDriverService $service)
    {
        $data = $request->validate(['valid_to' => ['required', 'date_format:Y-m-d\TH:i']]);
        $service->close($link, str_replace('T', ' ', $data['valid_to']).':00', $request->user()->id);

        return back()->with('success', 'Đã kết thúc ánh xạ. Lịch sử được giữ nguyên.');
    }
}
