<?php

namespace App\Services;

use App\Models\OcrJob;
use App\Models\OcrProcessingRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class OcrMonitoringService
{
    private const BACKLOG_STATUSES = ['PENDING', 'RETRY', 'PROCESSING'];

    private const TERMINAL_STATUSES = ['COMPLETED', 'EXCEPTION', 'FAILED'];

    public function dashboardData(): array
    {
        return [
            'summary' => $this->summary(),
            'runs' => $this->recentRuns(),
        ];
    }

    public function summary(): array
    {
        $timezone = (string) config('ocr.monitoring.timezone', 'Asia/Ho_Chi_Minh');
        $now = CarbonImmutable::now($timezone);
        $todayStart = $now->startOfDay();
        $todayEnd = $this->deadline($now);
        $startUtc = $todayStart->utc();
        $endUtc = $now->utc();

        $terminalToday = OcrJob::query()
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->whereBetween('processed_at', [$startUtc, $endUtc]);
        $receivedToday = OcrJob::query()->whereBetween('created_at', [$startUtc, $endUtc])->count();
        $processedToday = (clone $terminalToday)->count();
        $completedToday = (clone $terminalToday)->where('status', 'COMPLETED')->count();
        $exceptionToday = (clone $terminalToday)->where('status', 'EXCEPTION')->count();
        $failedToday = (clone $terminalToday)->where('status', 'FAILED')->count();
        $backlog = OcrJob::query()->whereIn('status', self::BACKLOG_STATUSES)->count();
        $processing = OcrJob::query()->where('status', 'PROCESSING')->count();
        $retrying = OcrJob::query()->where('status', 'RETRY')->count();
        $oldestBacklog = OcrJob::query()->whereIn('status', self::BACKLOG_STATUSES)->oldest('created_at')->first();
        $lastProcessedAt = OcrJob::query()->whereIn('status', self::TERMINAL_STATUSES)->max('processed_at');

        $completed15m = $this->processedSince($now->utc()->subMinutes(15));
        $completed1h = $this->processedSince($now->utc()->subHour());
        $effectiveRate = $this->effectiveHourlyRate($now, $processedToday, $completed1h, $startUtc);
        $remainingMinutes = max(0.01, $now->diffInMinutes($todayEnd, false));
        $requiredRate = $backlog > 0 ? $backlog / ($remainingMinutes / 60) : 0.0;
        $etaMinutes = $backlog > 0 && $effectiveRate > 0
            ? (int) ceil(($backlog / $effectiveRate) * 60)
            : null;
        $projectedFinish = $etaMinutes !== null ? $now->addMinutes($etaMinutes) : null;

        $runDurations = OcrProcessingRun::query()
            ->whereNotNull('duration_ms')
            ->whereBetween('finished_at', [$startUtc, $endUtc])
            ->orderBy('duration_ms')
            ->pluck('duration_ms')
            ->map(fn ($value): int => (int) $value);
        $runtime = $this->runtimeStats($runDurations);
        $capacity = $this->capacityStatus(
            $now,
            $todayStart,
            $todayEnd,
            $backlog,
            $oldestBacklog?->created_at?->toImmutable(),
            $lastProcessedAt ? CarbonImmutable::parse($lastProcessedAt)->setTimezone($timezone) : null,
            $effectiveRate,
            $requiredRate,
            $projectedFinish,
        );

        return [
            'date' => $now->format('Y-m-d'),
            'timezone' => 'GMT+7',
            'deadline' => $todayEnd->format('H:i'),
            'now' => $now->toIso8601String(),
            'received_today' => $receivedToday,
            'processed_today' => $processedToday,
            'completed_today' => $completedToday,
            'exception_today' => $exceptionToday,
            'failed_today' => $failedToday,
            'backlog' => $backlog,
            'processing' => $processing,
            'retrying' => $retrying,
            'completed_15m' => $completed15m,
            'completed_1h' => $completed1h,
            'hourly_rate' => round($effectiveRate, 1),
            'required_hourly_rate' => round($requiredRate, 1),
            'eta_minutes' => $etaMinutes,
            'projected_finish_at' => $projectedFinish?->toIso8601String(),
            'oldest_backlog_minutes' => $oldestBacklog
                ? max(0, (int) $oldestBacklog->created_at->diffInMinutes($now->utc()))
                : 0,
            'last_processed_at' => $lastProcessedAt
                ? CarbonImmutable::parse($lastProcessedAt)->setTimezone($timezone)->toIso8601String()
                : null,
            'runtime' => $runtime,
            'capacity' => $capacity,
        ];
    }

    public function recentRuns(?int $limit = null): array
    {
        $timezone = (string) config('ocr.monitoring.timezone', 'Asia/Ho_Chi_Minh');
        $limit ??= max(10, min(100, (int) config('ocr.monitoring.recent_limit', 50)));

        return OcrProcessingRun::query()
            ->with(['job.attachment.message', 'job.machine', 'job.processingRuns'])
            ->latest('started_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (OcrProcessingRun $run) use ($timezone): array {
                $job = $run->job;
                $message = $job?->attachment?->message;
                $duration = $run->duration_ms ?? max(0, $run->started_at->diffInMilliseconds(now()));
                $previousRun = $job?->processingRuns
                    ->where('id', '<', $run->id)
                    ->sortByDesc('id')
                    ->first();
                $queueStartedAt = $previousRun?->finished_at ?? $job?->created_at;
                $totalDuration = (int) ($job?->processingRuns->sum('duration_ms') ?? 0);
                if ($run->duration_ms === null) {
                    $totalDuration += (int) $duration;
                }

                return [
                    'id' => $run->id,
                    'job_id' => $run->ocr_job_id,
                    'worker_id' => $run->worker_id,
                    'stage' => $run->stage,
                    'attempt' => $run->attempt,
                    'status' => $run->status,
                    'duration_ms' => (int) $duration,
                    'total_job_duration_ms' => $totalDuration,
                    'queue_wait_ms' => $queueStartedAt
                        ? max(0, $queueStartedAt->diffInMilliseconds($run->started_at))
                        : 0,
                    'started_at' => $run->started_at->setTimezone($timezone)->toIso8601String(),
                    'finished_at' => $run->finished_at?->setTimezone($timezone)->toIso8601String(),
                    'job_status' => $job?->status,
                    'document_type' => $job?->document_type,
                    'asset_code' => $job?->asset_code ?: $job?->machine?->asset_code,
                    'group_id' => $message?->group_id,
                    'sender_name' => $message?->sender_name,
                    'sent_at' => $message?->sent_at?->setTimezone($timezone)->toIso8601String(),
                    'error_message' => $run->error_message,
                    'url' => $job ? route('ocr-reviews.show', $job) : null,
                ];
            })
            ->all();
    }

    public function notificationAlert(): ?array
    {
        $summary = $this->summary();
        $level = data_get($summary, 'capacity.level');
        if (! in_array($level, ['warning', 'danger'], true)) {
            return null;
        }

        return [
            'key' => "ocr-capacity:{$summary['date']}:{$level}",
            'level' => $level,
            'category' => 'ocr_capacity',
            'title' => $level === 'danger' ? 'OCR có nguy cơ không kịp ngày' : 'Công suất OCR đang sát ngưỡng',
            'message' => data_get($summary, 'capacity.message'),
            'url' => route('ocr-monitoring.index'),
            'backlog' => $summary['backlog'],
            'hourly_rate' => $summary['hourly_rate'],
            'projected_finish_at' => $summary['projected_finish_at'],
        ];
    }

    private function processedSince(CarbonImmutable $since): int
    {
        return OcrJob::query()
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->where('processed_at', '>=', $since)
            ->count();
    }

    private function effectiveHourlyRate(
        CarbonImmutable $now,
        int $processedToday,
        int $processedLastHour,
        CarbonImmutable $todayStartUtc,
    ): float {
        if ($processedLastHour > 0) {
            $first = OcrJob::query()
                ->whereIn('status', self::TERMINAL_STATUSES)
                ->where('processed_at', '>=', $now->utc()->subHour())
                ->min('processed_at');
            $hours = $first
                ? max(0.25, CarbonImmutable::parse($first)->diffInMinutes($now->utc()) / 60)
                : 1.0;

            return $processedLastHour / $hours;
        }

        if ($processedToday === 0) {
            return 0.0;
        }

        $first = OcrJob::query()
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->where('processed_at', '>=', $todayStartUtc)
            ->min('processed_at');
        $hours = $first
            ? max(0.25, CarbonImmutable::parse($first)->diffInMinutes($now->utc()) / 60)
            : max(0.25, $todayStartUtc->diffInMinutes($now->utc()) / 60);

        return $processedToday / $hours;
    }

    private function runtimeStats(Collection $durations): array
    {
        if ($durations->isEmpty()) {
            return ['runs' => 0, 'total_ms' => 0, 'average_ms' => 0, 'minimum_ms' => 0, 'maximum_ms' => 0, 'p50_ms' => 0, 'p95_ms' => 0];
        }

        return [
            'runs' => $durations->count(),
            'total_ms' => $durations->sum(),
            'average_ms' => (int) round($durations->average()),
            'minimum_ms' => $durations->first(),
            'maximum_ms' => $durations->last(),
            'p50_ms' => $this->percentile($durations, 0.50),
            'p95_ms' => $this->percentile($durations, 0.95),
        ];
    }

    private function percentile(Collection $sorted, float $percentile): int
    {
        $index = (int) ceil($percentile * $sorted->count()) - 1;

        return (int) $sorted->values()->get(max(0, $index), 0);
    }

    private function capacityStatus(
        CarbonImmutable $now,
        CarbonImmutable $todayStart,
        CarbonImmutable $deadline,
        int $backlog,
        ?CarbonImmutable $oldestBacklog,
        ?CarbonImmutable $lastProcessed,
        float $hourlyRate,
        float $requiredRate,
        ?CarbonImmutable $projectedFinish,
    ): array {
        if ($backlog === 0) {
            return ['level' => 'healthy', 'message' => 'Không còn ảnh chờ OCR.'];
        }

        $stalledMinutes = max(1, (int) config('ocr.monitoring.stalled_minutes', 15));
        $backlogAge = $oldestBacklog?->setTimezone($now->timezone)->diffInMinutes($now, false) ?? 0;
        $stalled = $backlogAge >= $stalledMinutes
            && ($lastProcessed === null || $lastProcessed->diffInMinutes($now, false) >= $stalledMinutes);
        $overdue = $oldestBacklog?->lessThan($todayStart->utc()) ?? false;

        if ($overdue || $stalled || ($projectedFinish !== null && $projectedFinish->greaterThan($deadline))) {
            $message = $overdue
                ? "Còn {$backlog} ảnh tồn từ ngày trước."
                : ($stalled
                    ? "Còn {$backlog} ảnh nhưng quá {$stalledMinutes} phút chưa hoàn thành ảnh nào."
                    : "Còn {$backlog} ảnh; tốc độ hiện tại dự kiến không xong trước {$deadline->format('H:i')}.");

            return ['level' => 'danger', 'message' => $message];
        }

        if ($projectedFinish === null) {
            return [
                'level' => 'warning',
                'message' => "Còn {$backlog} ảnh; đang chờ đủ dữ liệu để tính tốc độ và ETA.",
            ];
        }

        $warningAt = $deadline->subMinutes(max(1, (int) config('ocr.monitoring.warning_buffer_minutes', 60)));
        if ($projectedFinish->greaterThan($warningAt) || ($requiredRate > 0 && $hourlyRate < $requiredRate * 1.15)) {
            return [
                'level' => 'warning',
                'message' => "Còn {$backlog} ảnh; công suất đang sát ngưỡng cần thiết để xong trước {$deadline->format('H:i')}.",
            ];
        }

        return [
            'level' => 'healthy',
            'message' => "Còn {$backlog} ảnh; dự kiến hoàn thành lúc {$projectedFinish->format('H:i')}.",
        ];
    }

    private function deadline(CarbonImmutable $now): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) config('ocr.monitoring.deadline', '23:59')));

        return $now->setTime(max(0, min(23, $hour)), max(0, min(59, $minute)), 0);
    }
}
