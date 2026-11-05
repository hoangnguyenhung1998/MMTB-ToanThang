<?php

namespace App\Jobs;

use App\Services\Dossiers\Ai\DossierAiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeDossier implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 360;

    public function __construct(public int $runId)
    {
        $this->onConnection('dossiers')->onQueue('dossiers-ai');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DossierAiService $service): void
    {
        $delay = $service->step($this->runId);
        if ($delay) {
            self::dispatch($this->runId)->delay(now()->addSeconds($delay));
        }
    }
}
