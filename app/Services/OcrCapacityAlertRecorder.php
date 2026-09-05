<?php

namespace App\Services;

use App\Models\AutomationHealthAlert;

class OcrCapacityAlertRecorder
{
    public function __construct(
        private readonly OcrMonitoringService $monitoring,
        private readonly NotificationSyncService $notifications,
    ) {}

    public function evaluate(): void
    {
        $summary = $this->monitoring->summary();
        $this->notifications->syncForAllUsers();
        if (data_get($summary, 'capacity.level') !== 'danger') {
            return;
        }

        AutomationHealthAlert::query()->firstOrCreate(
            ['event_key' => "ocr-capacity:{$summary['date']}:danger"],
            [
                'kind' => 'OCR_CAPACITY',
                'payload' => [
                    'type' => 'OCR_CAPACITY',
                    'message' => data_get($summary, 'capacity.message'),
                    'backlog' => $summary['backlog'],
                    'hourly_rate' => $summary['hourly_rate'],
                    'required_hourly_rate' => $summary['required_hourly_rate'],
                    'projected_finish_at' => $summary['projected_finish_at'],
                    'dashboard_url' => route('ocr-monitoring.index'),
                ],
            ],
        );
    }
}
