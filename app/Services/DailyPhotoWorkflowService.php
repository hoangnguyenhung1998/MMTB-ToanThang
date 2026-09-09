<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\OcrJob;
use App\Models\ReconciliationRow;
use App\Services\Reconciliation\DailyPhotoSyncService;
use App\Services\Reconciliation\DailyTimeAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyPhotoWorkflowService
{
    public function requeue(OcrJob $job, string $type, int $userId): void
    {
        DB::transaction(function () use ($job, $type, $userId) {
            $job = OcrJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($job->status === 'PROCESSING') $this->invalid('Ảnh đang được xử lý.');
            if ($job->reviewed_at) $this->invalid('Ảnh đã được sửa/duyệt tay. Giữ kết quả hiện tại; dùng chức năng chỉnh sửa.');
            $before = $job->toArray();
            $job->update(['document_type' => $type, 'status' => $type === 'WEEKLY_JOURNAL' ? 'PAUSED' : ($type === 'UNKNOWN' ? 'EXCEPTION' : 'PENDING'),
                'review_status' => 'PENDING', 'claimed_by' => null, 'claimed_at' => null, 'lease_expires_at' => null,
                'error_message' => null, 'daily_photo_case_id' => null]);
            $this->audit($userId, 'daily_photo.requeued', $job, $before, $job->fresh()->toArray());
            // Withdraw the previous automatic evidence while this job is under review.
            if ($job->machine_id && $job->extracted_date) {
                \App\Models\ReconciliationPeriod::query()->whereIn('status', ['GENERATED', 'REVIEWING'])
                    ->whereDate('date_from', '<=', $job->extracted_date)->whereDate('date_to', '>=', $job->extracted_date->copy()->subDay())->get()
                    ->each(fn ($period) => app(DailyPhotoSyncService::class)->sync($period, $job->machine_id, $job->extracted_date->toDateString()));
            }
        });
    }

    public function allocate(ReconciliationRow $row, array $data, int $userId): void
    {
        DB::transaction(function () use ($row, $data, $userId) {
            $period = $row->period()->lockForUpdate()->firstOrFail();
            if (!in_array($period->status, ['GENERATED', 'REVIEWING'], true)) $this->invalid('Kỳ đã chốt hoặc chưa sinh dữ liệu.');
            $row = ReconciliationRow::query()->lockForUpdate()->findOrFail($row->id);
            if ($row->status === 'CONFIRMED') $this->invalid('Dòng đã xác nhận.');
            $before = $row->toArray();
            $sources = app(DailyPhotoSyncService::class)->sources($row, true)->keyBy('id');
            $intervals = [];
            $used = [];
            foreach ($data['intervals'] as $interval) {
                if (empty($interval['start']) && empty($interval['end']) && empty($interval['start_job_id']) && empty($interval['end_job_id'])) continue;
                foreach (['start', 'end'] as $edge) {
                    if (!empty($interval[$edge.'_job_id'])) {
                        $id = (int) $interval[$edge.'_job_id'];
                        $job = $sources->get($id);
                        if (!$job || in_array($id, $used, true)) $this->invalid('Ảnh không thuộc máy/ca này hoặc bị dùng hai lần.');
                        $used[] = $id;
                        $interval[$edge] = substr($job->extracted_time, 0, 5);
                        $interval[$edge.'_date'] = $job->extracted_date->toDateString();
                        if ($edge === 'start' && !$job->extracted_date->isSameDay($row->work_date)) $this->invalid('Ca phải bắt đầu trong ngày đang đối chiếu.');
                    } elseif (empty($data['manual_reason']) || empty($data['confirm_manual'])) {
                        $this->invalid('Giờ bổ sung cần ghi lý do và xác nhận thiếu ảnh.');
                    }
                    if (empty($interval[$edge])) $this->invalid('Ca phải có đủ giờ vào và ra.');
                }
                if (isset($interval['end_date']) && $interval['end_date'] !== $row->work_date->toDateString()
                    && ($interval['kind'] !== 'overtime_evening' || $interval['end'] >= $interval['start'])) $this->invalid('Ảnh ngày sau chỉ dùng cho ca tối qua đêm hợp lệ.');
                if ($interval['end'] < $interval['start'] && isset($interval['start_date'], $interval['end_date'])
                    && $interval['start_date'] === $interval['end_date']) $this->invalid('Hai ảnh cùng ngày không tạo thành ca qua đêm.');
                $intervals[] = $interval;
            }
            if (!$intervals) $this->invalid('Chọn ít nhất một ca.');
            $allocator = app(DailyTimeAllocator::class);
            $allocation = $allocator->allocate($intervals, true, $allocator->remainingRegularMinutes($row));
            app(DailyTimeAllocator::class)->assertWithinAssignment($allocation, $row);
            $dailySync = app(DailyPhotoSyncService::class);
            $row->update([...$allocation, 'rounded_check_in' => $allocation['confirmed_check_in'], 'rounded_check_out' => $allocation['confirmed_check_out'],
                'daily_intervals' => $intervals, 'daily_ocr_job_ids' => $used, 'manually_edited_at' => now(),
                'has_evidence_changes' => false, 'evidence_status' => 'DAILY_CONFIRMED',
                'evidence_signature' => $dailySync->signatureForRow($row, $dailySync->sources($row), $used),
                'evidence_summary' => $data['manual_reason'] ?? 'Đã xác nhận ghép ca từ ảnh.',
                'status' => 'DRAFT', 'reviewed_at' => null, 'reviewed_by' => null]);
            $this->audit($userId, 'daily_photo.allocated', $row, $before, $row->fresh()->toArray());
        });
    }

    public function suggestions(): array
    {
        $result = [];
        foreach (['work_content', 'work_location'] as $field) {
            $result[$field] = ReconciliationRow::query()->whereNotNull('manually_edited_at')->whereNotNull($field)
                ->where($field, '!=', '')->select($field)->distinct()->limit(300)->pluck($field)->all();
        }
        return $result;
    }

    private function audit(int $userId, string $event, $subject, array $before, array $after): void
    {
        ActivityLog::create(['user_id' => $userId, 'machine_id' => $subject->machine_id, 'event' => $event,
            'description' => $event, 'subject_type' => $subject::class, 'subject_id' => $subject->id,
            'properties' => compact('before', 'after'), 'occurred_at' => now()]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['daily_photos' => $message]);
    }
}
