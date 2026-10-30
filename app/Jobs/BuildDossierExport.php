<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class BuildDossierExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public string $exportId)
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
        DB::transaction(function () use ($jobs) {
            $jobs->export(\App\Models\DossierExport::lockForUpdate()->findOrFail($this->exportId));
        }, 3);
    }
}
