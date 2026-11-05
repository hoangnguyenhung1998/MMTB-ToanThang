<?php

namespace App\Services\Dossiers\Ai;

use App\Models\DossierWorker;

class DossierVision
{
    public function probe(): array
    {
        $worker = DossierWorker::where('last_seen_at', '>=', now()->subSeconds(120))->where('ai_ready', true)->latest('last_seen_at')->first();

        return ['configured' => (bool) (config('dossiers.ai.enabled') && $worker), 'provider' => $worker?->provider, 'model' => $worker?->model, 'ai_profile' => $worker?->ai_profile];
    }
}
