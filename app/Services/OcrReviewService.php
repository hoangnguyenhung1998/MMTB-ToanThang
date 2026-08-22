<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OcrReviewService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return OcrJob::query()
            ->with(['machine:id,asset_code', 'attachment.message'])
            ->when($filters['q'] ?? null, function (Builder $query, string $value): void {
                $search = '%'.trim($value).'%';
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('asset_code', 'like', $search)
                        ->orWhereHas('attachment.message', fn (Builder $message) => $message
                            ->where('message_id', 'like', $search)
                            ->orWhere('sender_name', 'like', $search));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $value) => $query->where('status', $value))
            ->when($filters['review_status'] ?? null, fn (Builder $query, string $value) => $query->where('review_status', $value))
            ->when($filters['document_type'] ?? null, fn (Builder $query, string $value) => $query->where('document_type', $value))
            ->when($filters['machine_id'] ?? null, fn (Builder $query, int|string $value) => $query->where('machine_id', $value))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('sent_at', '>=', $date)))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('sent_at', '<=', $date)))
            ->orderByRaw("CASE review_status WHEN 'PENDING' THEN 0 WHEN 'REJECTED' THEN 1 ELSE 2 END")
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
    }

    public function statusCounts(): Collection
    {
        return OcrJob::query()->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');
    }

    public function reviewStatusCounts(): Collection
    {
        return OcrJob::query()->selectRaw('review_status, COUNT(*) total')->groupBy('review_status')->pluck('total', 'review_status');
    }

    public function dailyOverview(string $date): Collection
    {
        return OcrJob::query()
            ->with('machine:id,asset_code')
            ->where('document_type', 'DAILY_TIMEMARK')
            ->whereDate('extracted_date', $date)
            ->get()
            ->groupBy(fn (OcrJob $job) => ($job->machine_id ?: 'unknown').'|'.$job->extracted_date?->format('Y-m-d'))
            ->map(function (Collection $jobs): array {
                $job = $jobs->first();

                return [
                    'machine' => $job->machine?->asset_code ?: $job->asset_code ?: 'Chưa xác định',
                    'date' => $job->extracted_date?->format('d/m/Y'),
                    'total' => $jobs->count(),
                    'pending' => $jobs->where('review_status', 'PENDING')->count(),
                    'exceptions' => $jobs->where('status', 'EXCEPTION')->count(),
                    'completed' => $jobs->whereIn('review_status', ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'])->count(),
                ];
            })
            ->sortByDesc('pending')
            ->values();
    }

    public function machineOptions(): Collection
    {
        return Machine::query()->orderBy('asset_code')->get(['id', 'asset_code']);
    }

    public function detail(OcrJob $job): OcrJob
    {
        return $job->load(['machine', 'attachment.message', 'journalDocument.rows', 'reviewer:id,name', 'activities.user:id,name']);
    }

    public function imageExists(OcrJob $job): bool
    {
        return $job->attachment
            && Storage::disk($job->attachment->storage_disk)->exists($job->attachment->storage_path);
    }

    public function review(OcrJob $job, array $data, User $user): OcrJob
    {
        return DB::transaction(function () use ($job, $data, $user): OcrJob {
            $before = $job->only(['status', 'review_status', 'machine_id', 'asset_code', 'extracted_date', 'extracted_time', 'exceptions']);
            $action = $data['action'];
            $changes = [
                'review_status' => match ($action) {
                    'approve' => 'APPROVED',
                    'correct' => 'CORRECTED',
                    'reject' => 'REJECTED',
                },
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? null,
            ];

            if ($action === 'correct') {
                $machine = Machine::query()->findOrFail($data['machine_id']);
                $changes += [
                    'machine_id' => $machine->id,
                    'asset_code' => $machine->asset_code,
                    'extracted_date' => $data['extracted_date'] ?? $job->extracted_date,
                    'extracted_time' => $data['extracted_time'] ?? $job->extracted_time,
                    'operator_name' => $data['operator_name'] ?? $job->operator_name,
                    'phone' => $data['phone'] ?? $job->phone,
                    'work_location' => $data['work_location'] ?? $job->work_location,
                    'status' => 'COMPLETED',
                    'exceptions' => null,
                ];
            }

            $job->update($changes);
            ActivityLog::query()->create([
                'user_id' => $user->id,
                'machine_id' => $job->machine_id,
                'event' => 'ocr.reviewed',
                'description' => "Hậu kiểm OCR job #{$job->id}: {$action}",
                'subject_type' => OcrJob::class,
                'subject_id' => $job->id,
                'properties' => [
                    'action' => $action,
                    'before' => $before,
                    'after' => $job->fresh()->only(array_keys($before)),
                ],
                'occurred_at' => now(),
            ]);

            return $job->fresh();
        });
    }

    public function bulkReview(array $data, User $user): int
    {
        $count = 0;
        foreach (OcrJob::query()->whereIn('id', $data['job_ids'])->get() as $job) {
            $this->review($job, [
                'action' => $data['action'],
                'review_notes' => $data['review_notes'] ?? null,
            ], $user);
            $count++;
        }

        return $count;
    }

    public function exceptionLabels(): array
    {
        return [
            'LOW_CONFIDENCE' => 'Độ tin cậy thấp',
            'MISSING_DATE' => 'Thiếu ngày',
            'MISSING_TIME' => 'Thiếu giờ',
            'UNKNOWN_ASSET_CODE' => 'Mã máy không tồn tại',
            'WRONG_DATE' => 'Sai ngày gửi',
            'JOURNAL_ROW_EXCEPTION' => 'Có dòng nhật trình cần kiểm tra',
        ];
    }
}
