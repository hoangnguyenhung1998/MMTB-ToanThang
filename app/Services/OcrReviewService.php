<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationEvidenceSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OcrReviewService
{
    public function __construct(
        private readonly ReconciliationEvidenceSyncService $evidenceSync,
        private readonly DailyPhotoCaseService $dailyPhotoCases,
        private readonly OcrRegressionCaseService $regressionCases,
        private readonly DailyPhotoExceptionReason $dailyPhotoReasons,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->with(['machine:id,asset_code', 'attachment.message', 'latestAiRescueAttempt'])
            ->orderByRaw("CASE review_status WHEN 'PENDING' THEN 0 WHEN 'REJECTED' THEN 1 ELSE 2 END")
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
    }

    public function filteredQuery(array $filters): Builder
    {
        $query = OcrJob::query()
            ->when(config('daily_photos.enabled') && empty($filters['document_type']), function (Builder $builder) use ($filters): void {
                $documentTypes = ['UNKNOWN', 'DAILY_TIMEMARK'];
                if (filled($filters['ai_status'] ?? null) || ($filters['ocr_source'] ?? null) === 'ai_attempted') {
                    array_push($documentTypes, 'IGNORED_HOUR_METER', 'IGNORED_NON_DAILY_PHOTO');
                }
                $builder->whereIn('document_type', $documentTypes);
            })
            ->when($filters['q'] ?? null, function (Builder $builder, string $value): void {
                $value = trim($value);
                $search = '%'.$value.'%';
                $builder->where(function (Builder $nested) use ($search, $value): void {
                    if (ctype_digit($value)) {
                        $nested->orWhere($nested->getModel()->getQualifiedKeyName(), (int) $value);
                    }
                    $nested->orWhere('asset_code', 'like', $search)
                        ->orWhere('observed_asset_code', 'like', $search)
                        ->orWhereHas('attachment.message', fn (Builder $message) => $message
                            ->where('message_id', 'like', $search)
                            ->orWhere('sender_id', 'like', $search)
                            ->orWhere('sender_name', 'like', $search));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($filters['review_status'] ?? null, fn (Builder $builder, string $value) => $builder->where('review_status', $value))
            ->when($filters['document_type'] ?? null, fn (Builder $builder, string $value) => $builder->where('document_type', $value))
            ->when($filters['machine_id'] ?? null, fn (Builder $builder, int|string $value) => $builder->where('machine_id', $value))
            ->when($filters['sender'] ?? null, function (Builder $builder, string $sender): void {
                $search = '%'.trim($sender).'%';
                $builder->whereHas('attachment.message', fn (Builder $message) => $message
                    ->where('sender_id', trim($sender))->orWhere('sender_name', 'like', $search));
            })
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder
                ->whereHas('attachment.message', fn (Builder $message) => $message
                    ->where('sent_at', '>=', CarbonImmutable::parse($date)->startOfDay())))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder
                ->whereHas('attachment.message', fn (Builder $message) => $message
                    ->where('sent_at', '<=', CarbonImmutable::parse($date)->endOfDay())));

        $this->applyWorkflowFilter($query, $filters['workflow'] ?? null);
        $this->applyOcrSourceFilter($query, $filters['ocr_source'] ?? null);
        $this->applyAiStatusFilter($query, $filters['ai_status'] ?? null);
        if (filled($filters['reason'] ?? null)) {
            $this->dailyPhotoReasons->applyToQuery($query, $filters['reason']);
        }

        return $query;
    }

    private function applyWorkflowFilter(Builder $query, ?string $workflow): void
    {
        match ($workflow) {
            'manual' => $query->where('document_type', 'DAILY_TIMEMARK')->where('status', 'EXCEPTION'),
            'canonical' => $query->where(function (Builder $builder): void {
                $builder->whereNotNull('daily_photo_case_id')->orWhereHas('dailyPhotoCaseEvidence');
            }),
            'protected' => $query->where(function (Builder $builder): void {
                $builder->whereNotNull('reviewed_at')
                    ->orWhereIn('review_status', ['APPROVED', 'CORRECTED', 'REJECTED'])
                    ->orWhere('machine_resolution_method', DailyPhotoMachineResolutionService::HUMAN);
            }),
            'reviewed' => $query->where(function (Builder $builder): void {
                $builder->whereNotNull('reviewed_at')
                    ->orWhereIn('review_status', ['APPROVED', 'CORRECTED', 'REJECTED']);
            }),
            default => null,
        };
    }

    private function applyOcrSourceFilter(Builder $query, ?string $source): void
    {
        match ($source) {
            'rapidocr', 'never_ai' => $query->whereDoesntHave('aiRescueAttempts'),
            'ai_attempted' => $query->whereHas('aiRescueAttempts'),
            default => null,
        };
    }

    private function applyAiStatusFilter(Builder $query, ?string $status): void
    {
        if ($status === 'never') {
            $query->whereDoesntHave('aiRescueAttempts');

            return;
        }
        if (! $status) {
            return;
        }

        $query->whereHas('latestAiRescueAttempt', function (Builder $attempt) use ($status): void {
            match ($status) {
                'queued' => $attempt->where('active_key', DailyPhotoAiRescueService::ACTIVE_KEY)->whereIn('status', ['PENDING', 'RETRY']),
                'processing' => $attempt->where('active_key', DailyPhotoAiRescueService::ACTIVE_KEY)->where('status', 'PROCESSING'),
                'active' => $attempt->where('active_key', DailyPhotoAiRescueService::ACTIVE_KEY)->whereIn('status', ['PENDING', 'RETRY', 'PROCESSING']),
                'resolved' => $attempt->where('final_resolution', 'RESOLVED'),
                'human_required' => $attempt->where('final_resolution', 'HUMAN_REQUIRED'),
                'non_daily' => $attempt->where('final_resolution', 'NON_DAILY'),
                'failed' => $attempt->where('final_resolution', 'FAILED'),
                'skipped' => $attempt->where('final_resolution', 'like', 'SKIPPED%'),
                'failed_or_skipped' => $attempt->where(function (Builder $nested): void {
                    $nested->where('final_resolution', 'FAILED')->orWhere('final_resolution', 'like', 'SKIPPED%');
                }),
                default => null,
            };
        });
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
        return DB::table('ocr_jobs')
            ->leftJoin('machines', 'machines.id', '=', 'ocr_jobs.machine_id')
            ->where('document_type', 'DAILY_TIMEMARK')
            ->whereDate('extracted_date', $date)
            ->selectRaw('ocr_jobs.machine_id, ocr_jobs.extracted_date')
            ->selectRaw('MAX(machines.asset_code) machine_asset_code, MAX(ocr_jobs.asset_code) observed_asset_code')
            ->selectRaw('COUNT(*) total')
            ->selectRaw("SUM(CASE WHEN review_status = 'PENDING' THEN 1 ELSE 0 END) pending")
            ->selectRaw("SUM(CASE WHEN ocr_jobs.status = 'EXCEPTION' THEN 1 ELSE 0 END) exceptions")
            ->selectRaw("SUM(CASE WHEN review_status IN ('AUTO_APPROVED','APPROVED','CORRECTED') THEN 1 ELSE 0 END) completed")
            ->groupBy('ocr_jobs.machine_id', 'ocr_jobs.extracted_date')
            ->orderByDesc('pending')
            ->get()
            ->map(fn (object $row): array => [
                'machine' => $row->machine_asset_code ?: $row->observed_asset_code ?: 'Chưa xác định',
                'date' => $row->extracted_date ? CarbonImmutable::parse($row->extracted_date)->format('d/m/Y') : null,
                'total' => (int) $row->total,
                'pending' => (int) $row->pending,
                'exceptions' => (int) $row->exceptions,
                'completed' => (int) $row->completed,
            ]);
    }

    public function machineOptions(): Collection
    {
        return Machine::query()->orderBy('asset_code')->get(['id', 'asset_code']);
    }

    public function detail(OcrJob $job): OcrJob
    {
        return $job->load(['machine', 'attachment.message', 'journalDocument.rows', 'reviewer:id,name', 'activities.user:id,name', 'aiRescueAttempts']);
    }

    public function imageExists(OcrJob $job): bool
    {
        return $job->attachment
            && Storage::disk($job->attachment->storage_disk)->exists($job->attachment->storage_path);
    }

    public function review(OcrJob $job, array $data, User $user): OcrJob
    {
        $previous = clone $job;
        $reviewed = DB::transaction(function () use ($job, $data, $user): OcrJob {
            $job = OcrJob::query()->lockForUpdate()->findOrFail($job->id);
            $wasDaily = $job->document_type === 'DAILY_TIMEMARK';
            $before = $job->only(['status', 'review_status', 'machine_id', 'asset_code', 'extracted_date', 'extracted_time', 'exceptions']);
            $requestedAction = $data['action'];
            $addRegressionCase = $requestedAction === 'correct_and_add_case';
            $expectedDisposition = $data['expected_disposition'] ?? 'DAILY_TIMEMARK';
            $markIgnored = $addRegressionCase && in_array($expectedDisposition, ['IGNORED_HOUR_METER', 'IGNORED_NON_DAILY_PHOTO'], true);
            $inputSnapshot = $addRegressionCase ? $this->regressionCases->snapshot($job) : [];
            $action = $addRegressionCase ? 'correct' : $requestedAction;
            $sameReviewedGroundTruth = $addRegressionCase
                && $job->reviewed_at
                && ($markIgnored
                    ? $job->document_type === $expectedDisposition
                    : (int) $job->machine_id === (int) ($data['machine_id'] ?? 0)
                        && $job->extracted_date?->format('Y-m-d') === ($data['extracted_date'] ?? null)
                        && substr((string) $job->extracted_time, 0, 5) === ($data['extracted_time'] ?? null));
            if ($sameReviewedGroundTruth) {
                $this->regressionCases->captureVerified($job->fresh(['attachment.message', 'machine']), $inputSnapshot, $data, $user);

                return $job->fresh(['dailyPhotoCase']);
            }
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

            if ($action === 'correct' && ! $markIgnored) {
                $machine = Machine::query()->findOrFail($data['machine_id']);
                $resolutionMetadata = $job->machine_resolution_metadata ?? [];
                $resolutionMetadata['human_resolution'] = [
                    'previous_method' => $job->machine_resolution_method,
                    'previous_machine_id' => $job->machine_id,
                    'resolved_by' => $user->id,
                    'resolved_at' => now()->toIso8601String(),
                    'version' => config('daily_photos.foundation_version'),
                ];
                $changes += [
                    'machine_id' => $machine->id,
                    'asset_code' => $machine->asset_code,
                    'machine_resolution_method' => DailyPhotoMachineResolutionService::HUMAN,
                    'machine_resolution_metadata' => $resolutionMetadata,
                    'sender_driver_link_id' => null,
                    'machine_driver_history_id' => null,
                    'machine_resolved_at' => now(),
                    'daily_photo_case_id' => null,
                    'extracted_date' => $data['extracted_date'] ?? $job->extracted_date,
                    'extracted_time' => $data['extracted_time'] ?? $job->extracted_time,
                    'operator_name' => $data['operator_name'] ?? $job->operator_name,
                    'phone' => $data['phone'] ?? $job->phone,
                    'work_location' => $data['work_location'] ?? $job->work_location,
                    'status' => 'COMPLETED',
                    'exceptions' => null,
                ];
            }

            if ($markIgnored) {
                $changes += [
                    'document_type' => $expectedDisposition,
                    'status' => 'COMPLETED',
                    'exceptions' => null,
                    'daily_photo_case_id' => null,
                ];
            }

            if ($action === 'approve' && ! $job->machine_resolution_method && $job->machine_id) {
                $changes['machine_resolution_method'] = DailyPhotoMachineResolutionService::HUMAN;
                $changes['machine_resolution_metadata'] = [
                    ...($job->machine_resolution_metadata ?? []),
                    'human_resolution' => [
                        'previous_method' => null,
                        'previous_machine_id' => $job->machine_id,
                        'resolved_by' => $user->id,
                        'resolved_at' => now()->toIso8601String(),
                        'version' => config('daily_photos.foundation_version'),
                    ],
                ];
                $changes['machine_resolved_at'] = now();
            }

            if ($job->document_type === 'DAILY_TIMEMARK' && $action !== 'reject' && ! $markIgnored) {
                $machineId = $changes['machine_id'] ?? $job->machine_id;
                $date = $changes['extracted_date'] ?? $job->extracted_date;
                $time = $changes['extracted_time'] ?? $job->extracted_time;
                if (! $machineId || ! $date || ! $time) {
                    throw ValidationException::withMessages([
                        'machine_id' => 'Ảnh hằng ngày phải đủ mã máy, ngày và giờ trước khi duyệt.',
                    ]);
                }

                $changes['status'] = 'COMPLETED';
                $changes['exceptions'] = null;
            }

            if ($action === 'reject') {
                $changes['daily_photo_case_id'] = null;
            }
            $job->update($changes);
            $fresh = $job->fresh();
            if ($wasDaily || $fresh->document_type === 'DAILY_TIMEMARK') {
                if ($action === 'reject' || $markIgnored) {
                    $this->dailyPhotoCases->detach($fresh);
                } else {
                    $this->dailyPhotoCases->materialize($fresh);
                }
            }
            if ($addRegressionCase) {
                $this->regressionCases->captureVerified($fresh->fresh(['attachment.message', 'machine']), $inputSnapshot, $data, $user);
            }
            ActivityLog::query()->create([
                'user_id' => $user->id,
                'machine_id' => $job->machine_id,
                'event' => 'ocr.reviewed',
                'description' => "Hậu kiểm OCR job #{$job->id}: {$action}",
                'subject_type' => OcrJob::class,
                'subject_id' => $job->id,
                'properties' => [
                    'action' => $requestedAction,
                    'before' => $before,
                    'after' => $job->fresh()->only(array_keys($before)),
                ],
                'occurred_at' => now(),
            ]);

            return $job->fresh(['dailyPhotoCase']);
        });

        if ($reviewed->document_type === 'DAILY_TIMEMARK') {
            if ($reviewed->machine_id && $reviewed->extracted_date) {
                $this->syncRelatedPeriods($reviewed);
            }
            if ($previous->machine_id && $previous->extracted_date
                && ($previous->machine_id !== $reviewed->machine_id || ! $previous->extracted_date->eq($reviewed->extracted_date))) {
                $this->syncRelatedPeriods($previous);
            }
        } elseif ($previous->document_type === 'DAILY_TIMEMARK' && $previous->machine_id && $previous->extracted_date) {
            $this->syncRelatedPeriods($previous);
        }

        return $reviewed;
    }

    private function syncRelatedPeriods(OcrJob $job): void
    {
        ReconciliationPeriod::query()
            ->whereIn('status', ['GENERATED', 'REVIEWING'])
            ->whereDate('date_from', '<=', $job->extracted_date)
            ->whereDate('date_to', '>=', config('daily_photos.enabled') ? $job->extracted_date->copy()->subDay() : $job->extracted_date)
            ->get()
            ->each(fn (ReconciliationPeriod $period) => $this->evidenceSync->sync(
                $period,
                $job->machine_id,
                $job->extracted_date->format('Y-m-d'),
            ));
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
                if (empty($rowData['work_date'])) {
                    $exceptions[] = 'MISSING_DATE';
                }
                $isStatusOnly = filled($rowData['error_explanation'] ?? null)
                    && empty($rowData['start_time'])
                    && empty($rowData['end_time']);
                if (! $isStatusOnly && (empty($rowData['start_time']) || empty($rowData['end_time']))) {
                    $exceptions[] = 'MISSING_TIME';
                }
                if (blank($rowData['work_content'] ?? null)) {
                    $exceptions[] = 'MISSING_WORK_CONTENT';
                }

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
                    'error_explanation' => $rowData['error_explanation'] ?? null,
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
            'NEW_JOB' => 'Nội dung công việc mới cần xác nhận',
        ];
    }
}
