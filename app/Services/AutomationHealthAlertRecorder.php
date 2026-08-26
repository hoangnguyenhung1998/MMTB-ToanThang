<?php

namespace App\Services;

use App\Models\AutomationHealthAlert;
use App\Models\AutomationIncident;

class AutomationHealthAlertRecorder
{
    public function record(AutomationIncident $incident, string $kind): void
    {
        $incident->loadMissing('service.node');
        AutomationHealthAlert::query()->firstOrCreate(
            ['event_key' => "incident:{$incident->id}:{$kind}"],
            [
                'automation_incident_id' => $incident->id,
                'kind' => $kind,
                'payload' => [
                    'node' => $incident->service?->node?->name,
                    'service' => $incident->service?->name,
                    'type' => $incident->type,
                    'message' => $incident->message,
                    'dashboard_url' => config('automation_health.dashboard_url'),
                ],
            ]
        );
    }
}
