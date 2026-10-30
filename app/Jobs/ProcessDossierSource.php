<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDossierSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $sourceId)
    {
        $this->onConnection('dossiers')->onQueue(config('dossiers.queue'));
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    // Compatibility for jobs serialized before the remote-worker release.
    public function handle(\App\Services\Dossiers\DossierRemoteJobs $jobs): void
    {
        $jobs->prepare(\App\Models\DossierSource::findOrFail($this->sourceId));
    }
}
