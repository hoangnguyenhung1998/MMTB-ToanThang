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
            'dossierProfiles' => \App\Models\DossierSenderProfile::with('driver')->get()->keyBy('sender_id'),
            'dossierDrivers' => \App\Models\Driver::orderBy('name')->get(['id', 'name']),
            'machines' => \App\Models\Machine::orderBy('asset_code')->get(['id', 'asset_code']),
            'senders' => $senders,
            'report' => $report,
            'reasonLabels' => DailyPhotoExceptionReason::LABELS,
            'recoveryRuns' => \App\Models\DailyPhotoRecoveryRun::where('user_id', request()->user()->id)->latest()->limit(10)->get(),
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

    public function recover(Request $request, \App\Services\DailyPhotoRecoveryRunService $runs)
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'sender_id' => ['required', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'reason' => ['nullable', 'in:'.implode(',', array_keys(DailyPhotoExceptionReason::LABELS))],
            'command_center_id' => ['nullable', 'integer', 'exists:command_centers,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);
        $requestId = $data['request_id'];
        unset($data['request_id']);
        $run = $runs->start($request->user(), array_filter($data, fn ($value) => filled($value)), $requestId);

        return redirect()->route('daily-photos.recovery.show', $run);
    }

    public function recoveryStatus(Request $request, \App\Models\DailyPhotoRecoveryRun $recoveryRun)
    {
        abort_unless($recoveryRun->user_id === $request->user()->id, 403);
        if ($request->expectsJson()) {
            return response()->json($recoveryRun->only(['id', 'status', 'mode', 'filters', 'counters', 'discovery_done', 'failures', 'error_code', 'error_context', 'finished_at']));
        }

        return view('daily-photos.recovery-run', ['run' => $recoveryRun]);
    }

    public function retryRecovery(Request $request, \App\Models\DailyPhotoRecoveryRun $recoveryRun, \App\Services\DailyPhotoRecoveryRunService $runs)
    {
        abort_unless($recoveryRun->user_id === $request->user()->id, 403);
        $runs->retry($recoveryRun);

        return redirect()->route('daily-photos.recovery.show', $recoveryRun);
    }

    public function closeLink(Request $request, int $link, ZaloSenderDriverService $service)
    {
        $data = $request->validate(['valid_to' => ['required', 'date_format:Y-m-d\TH:i']]);
        $service->close($link, str_replace('T', ' ', $data['valid_to']).':00', $request->user()->id);

        return back()->with('success', 'Đã kết thúc ánh xạ. Lịch sử được giữ nguyên.');
    }
}
