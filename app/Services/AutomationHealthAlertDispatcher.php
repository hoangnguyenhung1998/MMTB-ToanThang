<?php

namespace App\Services;

use App\Models\AutomationHealthAlert;
use Throwable;

class AutomationHealthAlertDispatcher
{
    public function __construct(private readonly TelegramAlertClient $telegram) {}

    public function dispatch(): array
    {
        if (! $this->telegram->enabled()) return ['sent' => 0, 'failed' => 0, 'skipped' => true];
        $sent = 0; $failed = 0;
        $alerts = AutomationHealthAlert::query()
            ->whereIn('status', ['PENDING', 'RETRY'])->where('attempts', '<', 5)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->oldest('id')->limit(50)->get();
        foreach ($alerts as $alert) {
            try {
                $this->telegram->send($this->message($alert));
                $alert->update(['status' => 'SENT', 'attempts' => $alert->attempts + 1, 'sent_at' => now(), 'next_attempt_at' => null, 'error_message' => null]);
                $sent++;
            } catch (Throwable $exception) {
                $attempts = $alert->attempts + 1;
                $alert->update(['status' => $attempts >= 5 ? 'FAILED' : 'RETRY', 'attempts' => $attempts, 'next_attempt_at' => $attempts >= 5 ? null : now()->addMinutes(5), 'error_message' => mb_substr($exception->getMessage(), 0, 2000)]);
                $failed++;
            }
        }
        return compact('sent', 'failed') + ['skipped' => false];
    }

    private function message(AutomationHealthAlert $alert): string
    {
        $payload = $alert->payload;
        $recovered = $alert->kind === 'RECOVERED';
        $icon = $recovered ? '✅' : (($payload['type'] ?? '') === 'OFFLINE' ? '🔴' : '🟠');
        $title = $recovered ? 'Dịch vụ đã phục hồi' : match ($payload['type'] ?? '') {
            'OFFLINE' => 'Dịch vụ mất kết nối', 'HUNG' => 'Phát hiện job treo', default => 'Dịch vụ lỗi liên tiếp',
        };
        return implode("\n", [
            "{$icon} <b>{$title}</b>", '',
            '<b>Node:</b> '.$this->escape($payload['node'] ?? 'Không rõ'),
            '<b>Dịch vụ:</b> '.$this->escape($payload['service'] ?? 'Không rõ'),
            '<b>Chi tiết:</b> '.$this->escape($payload['message'] ?? ''), '',
            '<a href="'.$this->escape($payload['dashboard_url'] ?? '').'">Mở Dashboard giám sát</a>',
        ]);
    }

    private function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
