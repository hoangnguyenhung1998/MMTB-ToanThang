<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DailyPhotoAiRescueAttempt;
use App\Models\OcrJob;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

class DailyPhotoAiRescueBatchService
{
    public const PREVIEW_VERSION = 'daily-photo-ai-rescue-preview-v1';

    private const CATEGORIES = [
        'eligible',
        'protected',
        'already_resolved',
        'ai_processing',
        'ai_already_resolved',
        'ai_unresolved_previous',
        'ai_failed_previous',
        'missing_source',
        'other_skipped',
    ];

    public function __construct(
        private readonly DailyPhotoBacklogService $backlog,
        private readonly DailyPhotoAiRescueService $rescue,
    ) {}

    public function dashboard(): array
    {
        $report = $this->backlog->report();
        $rows = collect($report['rows']);
        $reasonGroups = collect(DailyPhotoExceptionReason::LABELS)->map(function (string $label, string $reason) use ($rows): array {
            $ids = $rows->filter(fn (array $row): bool => in_array($reason, $row['reasons'], true))
                ->pluck('job.id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

            return ['code' => $reason, 'label' => $label, 'count' => count($ids), 'job_ids' => $ids];
        })->filter(fn (array $group): bool => $group['count'] > 0)->values();

        $latest = $this->latestAttempts();
        $metrics = [
            'never_attempted' => 0,
            'queued_processing' => 0,
            'resolved' => 0,
            'non_daily' => 0,
            'human_required' => 0,
            'failed' => 0,
            'skipped' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];
        foreach ($latest as $attempt) {
            $metrics['prompt_tokens'] += (int) ($attempt->prompt_tokens ?? 0);
            $metrics['completion_tokens'] += (int) ($attempt->completion_tokens ?? 0);
            $metrics['total_tokens'] += (int) ($attempt->total_tokens ?? 0);
            if ($attempt->active_key === DailyPhotoAiRescueService::ACTIVE_KEY) {
                $metrics['queued_processing']++;
            } elseif ($attempt->final_resolution === 'RESOLVED') {
                $metrics['resolved']++;
            } elseif ($attempt->final_resolution === 'NON_DAILY') {
                $metrics['non_daily']++;
            } elseif ($attempt->final_resolution === 'HUMAN_REQUIRED') {
                $metrics['human_required']++;
            } elseif ($attempt->final_resolution === 'FAILED') {
                $metrics['failed']++;
            } elseif (str_starts_with((string) $attempt->final_resolution, 'SKIPPED')) {
                $metrics['skipped']++;
            }
        }
        $latestByJob = $latest->keyBy('ocr_job_id');
        foreach ($rows as $row) {
            $job = $row['job'];
            if (! $latestByJob->has($job->id) && $this->category($job, $row, null) === 'eligible') {
                $metrics['never_attempted']++;
            }
        }

        return [
            'reason_groups' => $reasonGroups,
            'manual_unique_photos' => $rows->pluck('job.id')->unique()->count(),
            'metrics' => $metrics,
        ];
    }

    public function preview(array $reasonGroups): array
    {
        $reasonGroups = $this->normalizeReasons($reasonGroups);
        $rows = collect($this->backlog->report()['rows'])
            ->filter(fn (array $row): bool => collect($row['reasons'])->intersect($reasonGroups)->isNotEmpty())
            ->unique('job.id')->values();
        $latest = $this->latestAttempts($rows->pluck('job.id')->all())->keyBy('ocr_job_id');
        $counts = $this->emptyCounts();
        $matchedRecords = 0;

        foreach ($rows as $row) {
            $matchedRecords += collect($row['reasons'])->intersect($reasonGroups)->count();
            $counts[$this->category($row['job'], $row, $latest->get($row['job']->id))]++;
        }

        $jobIds = $rows->pluck('job.id')->map(fn ($id): int => (int) $id)->values()->all();
        $payload = [
            'version' => self::PREVIEW_VERSION,
            'created_at' => now()->timestamp,
            'reason_groups' => $reasonGroups,
            'job_ids' => $jobIds,
        ];

        return [
            'reason_groups' => $reasonGroups,
            'reason_labels' => collect(DailyPhotoExceptionReason::LABELS)->only($reasonGroups)->all(),
            'matched_records' => $matchedRecords,
            'unique_photos' => count($jobIds),
            'will_queue' => $counts['eligible'],
            'counts' => $counts,
            'preview_token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }

    public function execute(string $previewToken, User $user): array
    {
        $payload = $this->decodePreview($previewToken);
        $jobs = OcrJob::query()->with([
            'attachment.message',
            'machine:id,asset_code',
            'dailyPhotoCaseEvidence.dailyPhotoCase',
        ])->whereKey($payload['job_ids'])->orderBy('id')->get();
        $manualJobs = $jobs->filter(fn (OcrJob $job): bool => $job->document_type === 'DAILY_TIMEMARK' && $job->status === 'EXCEPTION');
        $rows = $manualJobs->isEmpty() ? collect() : $this->backlog->analyse($manualJobs)->keyBy('job.id');
        $latest = $this->latestAttempts($jobs->modelKeys())->keyBy('ocr_job_id');
        $counts = $this->emptyCounts();
        $counts['other_skipped'] += count($payload['job_ids']) - $jobs->count();
        $accepted = 0;

        foreach ($jobs as $job) {
            $category = $this->category($job, $rows->get($job->id), $latest->get($job->id));
            if ($category !== 'eligible') {
                $counts[$category]++;

                continue;
            }

            try {
                $attempt = $this->rescue->request($job);
                if ($attempt->wasRecentlyCreated) {
                    $accepted++;
                    $counts['eligible']++;
                } else {
                    $counts['ai_processing']++;
                }
            } catch (ValidationException) {
                $counts['other_skipped']++;
            }
        }

        ActivityLog::query()->create([
            'user_id' => $user->id,
            'event' => 'ocr.ai_rescue_bulk_requested',
            'description' => "Yêu cầu AI Rescue hàng loạt: {$accepted} ảnh được tiếp nhận.",
            'properties' => [
                'reason_groups' => $payload['reason_groups'],
                'preview_unique_photos' => count($payload['job_ids']),
                'accepted' => $accepted,
                'counts' => $counts,
            ],
            'occurred_at' => now(),
        ]);

        return [
            'preview_unique_photos' => count($payload['job_ids']),
            'accepted' => $accepted,
            'skipped' => count($payload['job_ids']) - $accepted,
            'counts' => $counts,
        ];
    }

    public function requestSingle(OcrJob $job, User $user): array
    {
        $job->loadMissing(['attachment.message', 'machine:id,asset_code', 'dailyPhotoCaseEvidence.dailyPhotoCase']);
        $row = $job->document_type === 'DAILY_TIMEMARK' && $job->status === 'EXCEPTION'
            ? $this->backlog->analyse(collect([$job]))->first()
            : null;
        $latest = $job->latestAiRescueAttempt()->first();
        $category = $this->category($job, $row, $latest);
        if ($category !== 'eligible') {
            throw ValidationException::withMessages([
                'ai_rescue' => $this->categoryMessage($category),
            ]);
        }

        $attempt = $this->rescue->request($job);
        if ($attempt->wasRecentlyCreated) {
            ActivityLog::query()->create([
                'user_id' => $user->id,
                'machine_id' => $job->machine_id,
                'event' => 'ocr.ai_rescue_requested',
                'description' => "Yêu cầu AI Rescue cho OCR job #{$job->id}.",
                'subject_type' => OcrJob::class,
                'subject_id' => $job->id,
                'properties' => ['attempt_id' => $attempt->id],
                'occurred_at' => now(),
            ]);
        }

        return ['attempt' => $attempt, 'created' => $attempt->wasRecentlyCreated];
    }

    public function state(OcrJob $job): array
    {
        $job->loadMissing(['attachment.message', 'machine:id,asset_code', 'dailyPhotoCaseEvidence.dailyPhotoCase', 'aiRescueAttempts']);
        $row = $job->document_type === 'DAILY_TIMEMARK' && $job->status === 'EXCEPTION'
            ? $this->backlog->analyse(collect([$job]))->first()
            : null;
        $latest = $job->aiRescueAttempts->sortByDesc('id')->first();
        $category = $this->category($job, $row, $latest);

        return [
            'eligible' => $category === 'eligible',
            'category' => $category,
            'message' => $this->categoryMessage($category),
            'latest' => $latest,
            'history' => $job->aiRescueAttempts->sortByDesc('id')->values(),
        ];
    }

    private function category(OcrJob $job, ?array $row, ?DailyPhotoAiRescueAttempt $latest): string
    {
        if ($latest?->active_key === DailyPhotoAiRescueService::ACTIVE_KEY
            && in_array($latest->status, ['PENDING', 'RETRY', 'PROCESSING'], true)) {
            return 'ai_processing';
        }
        if (in_array($latest?->final_resolution, ['RESOLVED', 'NON_DAILY'], true)) {
            return 'ai_already_resolved';
        }

        $reason = $this->rescue->eligibilityReason($job, $row);
        if ($reason !== null) {
            return match (true) {
                str_starts_with($reason, 'PROTECTED'), $reason === 'CANONICAL' => 'protected',
                $reason === 'SOURCE_MISSING' => 'missing_source',
                in_array($reason, ['NOT_MANUAL', 'NO_LONGER_MANUAL'], true) => 'already_resolved',
                default => 'other_skipped',
            };
        }

        return match ($latest?->final_resolution) {
            'HUMAN_REQUIRED' => 'ai_unresolved_previous',
            'FAILED' => 'ai_failed_previous',
            'SKIPPED_PROTECTED', 'SKIPPED_STALE' => 'other_skipped',
            default => $latest ? 'other_skipped' : 'eligible',
        };
    }

    private function latestAttempts(array $jobIds = []): Collection
    {
        $query = DailyPhotoAiRescueAttempt::query()->orderByDesc('id');
        if ($jobIds !== []) {
            $query->whereIn('ocr_job_id', $jobIds);
        }

        return $query->get()->unique('ocr_job_id')->values();
    }

    private function normalizeReasons(array $reasons): array
    {
        $allowed = array_keys(DailyPhotoExceptionReason::LABELS);
        $normalized = collect($reasons)->map(fn ($reason): string => trim((string) $reason))
            ->filter(fn (string $reason): bool => in_array($reason, $allowed, true))->unique()->values()->all();
        if ($normalized === []) {
            throw ValidationException::withMessages(['reason_groups' => 'Chọn ít nhất một nhóm lỗi Manual.']);
        }

        return $normalized;
    }

    private function decodePreview(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['preview_token' => 'Preview AI Rescue không hợp lệ. Vui lòng tạo preview mới.']);
        }
        $ttl = max(1, (int) config('daily_photos.ai_rescue.preview_ttl_minutes', 30));
        if (($payload['version'] ?? null) !== self::PREVIEW_VERSION
            || ! is_array($payload['job_ids'] ?? null)
            || ! is_array($payload['reason_groups'] ?? null)
            || (int) ($payload['created_at'] ?? 0) < now()->subMinutes($ttl)->timestamp) {
            throw ValidationException::withMessages(['preview_token' => 'Preview AI Rescue đã hết hạn hoặc không hợp lệ.']);
        }

        $payload['job_ids'] = collect($payload['job_ids'])->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
        $payload['reason_groups'] = $this->normalizeReasons($payload['reason_groups']);

        return $payload;
    }

    private function emptyCounts(): array
    {
        return array_fill_keys(self::CATEGORIES, 0);
    }

    private function categoryMessage(string $category): string
    {
        return match ($category) {
            'eligible' => 'Ảnh đủ điều kiện OCR lại bằng AI.',
            'protected' => 'Ảnh đã được người dùng xử lý hoặc thuộc dữ liệu được bảo vệ.',
            'already_resolved' => 'Ảnh không còn ở trạng thái Manual cần AI Rescue.',
            'ai_processing' => 'Ảnh đã có AI Rescue đang chờ hoặc đang xử lý.',
            'ai_already_resolved' => 'Ảnh đã được AI Rescue xử lý thành công.',
            'ai_unresolved_previous' => 'AI Rescue trước đó chưa giải quyết được ảnh; mặc định không tự chạy lại.',
            'ai_failed_previous' => 'AI Rescue trước đó đã thất bại; mặc định không tự chạy lại.',
            'missing_source' => 'Không tìm thấy ảnh gốc để gửi AI.',
            default => 'Ảnh không còn đủ điều kiện AI Rescue.',
        };
    }
}
