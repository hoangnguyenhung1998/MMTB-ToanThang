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
use Illuminate\Validation\ValidationException;

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


    public function updateJournal(OcrJob $job, array $data, User $user): OcrJob
    {
        if ($job->document_type !== 'WEEKLY_JOURNAL' || ! $job->journalDocument) {
            throw ValidationException::withMessages(['document_type' => 'Job này không phải nhật trình tuần.']);
        }

        return DB::transaction(function () use ($job, $data, $user): OcrJob {
            $document = $job->journalDocument()->with('rows')->firstOrFail();
            $before = [
                'job' => $job->only(['status', 'review_status', 'machine_id', 'asset_code', 'exceptions']),
                'document' => $document->only(['machine_id', 'asset_code', 'exceptions']),
                'rows' => $document->rows->map->toArray()->all(),
            ];
            $action = $data['action'];
            $machine = Machine::query()->findOrFail($data['machine_id']);

            if ($action === 'reject') {
                $job->update([
                    'review_status' => 'REJECTED',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                    'review_notes' => $data['review_notes'] ?? null,
                ]);
                $this->logJournalReview($job, $user, $action, $before);

                return $job->fresh(['journalDocument.rows']);
            }

            $existingRows = $document->rows->keyBy('id');
            $preparedRows = [];
            foreach ($data['rows'] ?? [] as $rowData) {
                if (! empty($rowData['delete'])) {
                    continue;
                }

                if (! empty($rowData['id']) && ! $existingRows->has((int) $rowData['id'])) {
                    throw ValidationException::withMessages(['rows' => 'Dòng nhật trình không thuộc tài liệu này.']);
                }

                $exceptions = [];
                if (empty($rowData['work_date'])) $exceptions[] = 'MISSING_DATE';
                if (empty($rowData['start_time']) || empty($rowData['end_time'])) $exceptions[] = 'MISSING_TIME';
                if (blank($rowData['work_content'] ?? null)) $exceptions[] = 'MISSING_WORK_CONTENT';

                $totalMinutes = null;
                if (! empty($rowData['start_time']) && ! empty($rowData['end_time'])) {
                    $totalMinutes = $this->calculateJournalDuration(
                        $rowData['start_time'],
                        $rowData['end_time'],
                    );
                }

                $confidence = (float) ($rowData['confidence'] ?? 1);
                if ($action !== 'approve' && $confidence < (float) config('ocr.minimum_confidence', 0.8)) {
                    $exceptions[] = 'LOW_CONFIDENCE';
                }

                $oldRow = ! empty($rowData['id']) ? $existingRows->get((int) $rowData['id']) : null;
                $preparedRows[] = [
                    'work_date' => $rowData['work_date'] ?? null,
                    'start_time' => $rowData['start_time'] ?? null,
                    'end_time' => $rowData['end_time'] ?? null,
                    'total_minutes' => $totalMinutes,
                    'work_content' => $rowData['work_content'] ?? null,
                    'quantity' => $rowData['quantity'] ?? null,
                    'unit' => $rowData['unit'] ?? null,
                    'work_location' => $rowData['work_location'] ?? null,
                    'operator_name' => $rowData['operator_name'] ?? null,
                    'confidence' => $confidence,
                    'raw_data' => $oldRow?->raw_data,
                    'exceptions' => $exceptions === [] ? null : array_values(array_unique($exceptions)),
                ];
            }

            if ($preparedRows === []) {
                throw ValidationException::withMessages(['rows' => 'Nhật trình phải còn ít nhất một dòng.']);
            }

            $hasExceptions = collect($preparedRows)->contains(fn (array $row) => ! empty($row['exceptions']));
            if ($action === 'approve' && $hasExceptions) {
                throw ValidationException::withMessages(['rows' => 'Không thể duyệt khi vẫn còn dòng thiếu hoặc sai dữ liệu.']);
            }

            $document->rows()->delete();
            foreach ($preparedRows as $index => $row) {
                $document->rows()->create(['row_number' => $index + 1, ...$row]);
            }

            $document->update([
                'machine_id' => $machine->id,
                'asset_code' => $machine->asset_code,
                'exceptions' => $hasExceptions ? ['JOURNAL_ROW_EXCEPTION'] : null,
            ]);
            $job->update([
                'machine_id' => $machine->id,
                'asset_code' => $machine->asset_code,
                'status' => $hasExceptions ? 'EXCEPTION' : 'COMPLETED',
                'exceptions' => $hasExceptions ? ['JOURNAL_ROW_EXCEPTION'] : null,
                'review_status' => $action === 'approve' ? 'APPROVED' : 'PENDING',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? null,
            ]);

            $this->logJournalReview($job, $user, $action, $before);

            return $job->fresh(['journalDocument.rows', 'machine']);
        });
    }

    private function calculateJournalDuration(string $startTime, string $endTime): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $startTime));
        [$endHour, $endMinute] = array_map('intval', explode(':', $endTime));
        $minutes = ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute);

        // A shift may continue after midnight. It still belongs to the work date
        // recorded on this row; the next row's date starts the next work day.
        return $minutes < 0 ? $minutes + 1440 : $minutes;
    }

    private function logJournalReview(OcrJob $job, User $user, string $action, array $before): void
    {
        $fresh = $job->fresh(['journalDocument.rows']);
        ActivityLog::query()->create([
            'user_id' => $user->id,
            'machine_id' => $fresh->machine_id,
            'event' => 'ocr.journal_updated',
            'description' => "Chỉnh sửa nhật trình OCR job #{$fresh->id}: {$action}",
            'subject_type' => OcrJob::class,
            'subject_id' => $fresh->id,
            'properties' => [
                'action' => $action,
                'before' => $before,
                'after' => [
                    'job' => $fresh->only(['status', 'review_status', 'machine_id', 'asset_code', 'exceptions']),
                    'document' => $fresh->journalDocument?->only(['machine_id', 'asset_code', 'exceptions']),
                    'rows' => $fresh->journalDocument?->rows->map->toArray()->all(),
                ],
            ],
            'occurred_at' => now(),
        ]);
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
            'MISSING_WORK_CONTENT' => 'Thiếu nội dung công việc',
            'INVALID_TIME_RANGE' => 'Giờ kết thúc phải sau giờ bắt đầu',
            'UNKNOWN_ASSET_CODE' => 'Mã máy không tồn tại',
            'WRONG_DATE' => 'Sai ngày gửi',
            'JOURNAL_ROW_EXCEPTION' => 'Có dòng nhật trình cần kiểm tra',
        ];
    }
}
