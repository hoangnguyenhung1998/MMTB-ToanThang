<?php

namespace App\Services;

use App\Models\AiReconciliationAlert;
use App\Models\AiReconciliationJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class AiReconciliationAlertDispatcher
{
    public function __construct(
        private readonly AiReconciliationAlertRecorder $recorder,
        private readonly TelegramAlertClient $telegram,
    ) {
    }

    public function dispatchUrgent(): array
    {
        $this->stageOverdueWaitingJobs();

        if (! $this->telegram->enabled()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true];
        }

        $alerts = $this->dueAlerts()
            ->whereIn('kind', ['EXCEPTION', 'FAILED', 'WAITING_EVIDENCE', 'DAILY_DIGEST'])
            ->oldest('id')
            ->limit(50)
            ->get();

        return $this->sendIndividually($alerts);
    }

    public function dispatchWarnings(): array
    {
        if (! $this->telegram->enabled()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true];
        }

        $alerts = $this->dueAlerts()
            ->where('kind', 'WARNING')
            ->oldest('id')
            ->limit(100)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($alerts->chunk(10) as $batch) {
            try {
                $this->telegram->send($this->warningBatchMessage($batch));
                $this->markSent($batch);
                $sent += $batch->count();
            } catch (Throwable $exception) {
                $this->markFailed($batch, $exception);
                $failed += $batch->count();
            }
        }

        return compact('sent', 'failed') + ['skipped' => false];
    }

    public function dispatchDailyDigest(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $workDate = $now->subDay()->toDateString();
        $fingerprint = "daily-digest:{$workDate}";

        $alert = AiReconciliationAlert::query()->firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'kind' => 'DAILY_DIGEST',
                'severity' => 'INFO',
                'payload' => $this->dailyDigestPayload($workDate),
            ],
        );

        if ($alert->status === 'SENT') {
            return ['sent' => 0, 'failed' => 0, 'skipped' => false];
        }

        if (! $this->telegram->enabled()) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true];
        }

        return $this->sendIndividually(new Collection([$alert]));
    }

    private function stageOverdueWaitingJobs(): void
    {
        $threshold = now()->subHours(
            (int) config('mmtb.reconciliation_alerts.waiting_evidence_hours', 2),
        );

        AiReconciliationJob::query()
            ->with('machine:id,asset_code')
            ->where('status', 'WAITING_EVIDENCE')
            ->where('updated_at', '<=', $threshold)
            ->eachById(fn (AiReconciliationJob $job) => $this->recorder->recordWaitingJob($job));
    }

    private function dueAlerts()
    {
        return AiReconciliationAlert::query()
            ->whereIn('status', ['PENDING', 'RETRY'])
            ->where('attempts', '<', (int) config('mmtb.reconciliation_alerts.max_attempts', 5))
            ->where(fn ($query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()));
    }

    private function sendIndividually(Collection $alerts): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($alerts as $alert) {
            try {
                $this->telegram->send($this->message($alert));
                $this->markSent(new Collection([$alert]));
                $sent++;
            } catch (Throwable $exception) {
                $this->markFailed(new Collection([$alert]), $exception);
                $failed++;
            }
        }

        return compact('sent', 'failed') + ['skipped' => false];
    }

    private function markSent(Collection $alerts): void
    {
        foreach ($alerts as $alert) {
            $alert->update([
                'status' => 'SENT',
                'attempts' => $alert->attempts + 1,
                'sent_at' => now(),
                'next_attempt_at' => null,
                'error_message' => null,
            ]);
        }
    }

    private function markFailed(Collection $alerts, Throwable $exception): void
    {
        foreach ($alerts as $alert) {
            $attempts = $alert->attempts + 1;
            $exhausted = $attempts >= (int) config('mmtb.reconciliation_alerts.max_attempts', 5);

            $alert->update([
                'status' => $exhausted ? 'FAILED' : 'RETRY',
                'attempts' => $attempts,
                'next_attempt_at' => $exhausted
                    ? null
                    : now()->addMinutes((int) config('mmtb.reconciliation_alerts.retry_minutes', 5)),
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
        }
    }

    private function message(AiReconciliationAlert $alert): string
    {
        if ($alert->kind === 'DAILY_DIGEST') {
            return $this->dailyDigestMessage($alert->payload);
        }

        $payload = $alert->payload;
        $icon = match ($alert->kind) {
            'EXCEPTION', 'FAILED' => '🔴',
            'WAITING_EVIDENCE' => '🟠',
            default => '🟡',
        };
        $title = match ($alert->kind) {
            'EXCEPTION' => 'Ngoại lệ đối soát',
            'FAILED' => 'Lỗi xử lý đối soát',
            'WAITING_EVIDENCE' => 'Chờ bằng chứng quá hạn',
            default => 'Cảnh báo đối soát',
        };
        $detail = mb_substr(
            (string) ($payload['summary'] ?? $payload['error'] ?? $payload['reason'] ?? 'Cần kiểm tra trên Dashboard.'),
            0,
            1000,
        );

        return implode("\n", [
            "{$icon} <b>{$title}</b>",
            '',
            '<b>Máy:</b> '.$this->escape($payload['asset_code'] ?? 'Không rõ'),
            '<b>Ngày:</b> '.$this->escape($payload['work_date'] ?? 'Không rõ'),
            '<b>Chi tiết:</b> '.$this->escape((string) $detail),
            '',
            '<a href="'.$this->escape($payload['dashboard_url'] ?? '').'">Mở Dashboard đối soát</a>',
        ]);
    }

    private function warningBatchMessage(Collection $alerts): string
    {
        $lines = ['🟡 <b>Tổng hợp cảnh báo đối soát</b>', ''];

        foreach ($alerts as $alert) {
            $payload = $alert->payload;
            $lines[] = '• <b>'.$this->escape($payload['asset_code'] ?? 'Không rõ').'</b>'
                .' · '.$this->escape($payload['work_date'] ?? 'Không rõ')
                .' — '.$this->escape(mb_substr((string) ($payload['summary'] ?? 'Cần kiểm tra'), 0, 250));
            $lines[] = '<a href="'.$this->escape($payload['dashboard_url'] ?? '').'">Xem chi tiết</a>';
        }

        return implode("\n", $lines);
    }

    private function dailyDigestPayload(string $workDate): array
    {
        $query = AiReconciliationJob::query()->whereDate('work_date', $workDate);

        return [
            'work_date' => CarbonImmutable::parse($workDate)->format('d/m/Y'),
            'total' => (clone $query)->count(),
            'matched' => $this->outcomeCount($query, 'MATCHED'),
            'warning' => $this->outcomeCount($query, 'WARNING'),
            'exception' => $this->outcomeCount($query, 'EXCEPTION'),
            'waiting' => (clone $query)->where('status', 'WAITING_EVIDENCE')->count(),
            'failed' => (clone $query)->where('status', 'FAILED')->count(),
            'dashboard_url' => config('mmtb.reconciliation_alerts.dashboard_url')
                ?: rtrim((string) config('app.url'), '/').'/ai-reconciliation',
        ];
    }

    private function outcomeCount($query, string $outcome): int
    {
        return (clone $query)->whereHas(
            'latestSubmission',
            fn ($submission) => $submission->where('outcome', $outcome),
        )->count();
    }

    private function dailyDigestMessage(array $payload): string
    {
        return implode("\n", [
            '📊 <b>Báo cáo đối soát ngày '.$this->escape($payload['work_date']).'</b>',
            '',
            'Tổng job: <b>'.$payload['total'].'</b>',
            '✅ Đã khớp: <b>'.$payload['matched'].'</b>',
            '🟡 Cảnh báo: <b>'.$payload['warning'].'</b>',
            '🔴 Ngoại lệ: <b>'.$payload['exception'].'</b>',
            '🟠 Chờ bằng chứng: <b>'.$payload['waiting'].'</b>',
            '❌ Lỗi xử lý: <b>'.$payload['failed'].'</b>',
            '',
            '<a href="'.$this->escape($payload['dashboard_url']).'">Mở Dashboard đối soát</a>',
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
