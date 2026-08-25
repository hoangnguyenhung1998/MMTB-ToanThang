<?php

namespace App\Observers;

use App\Models\AiReconciliationJob;
use App\Services\AiReconciliationAlertRecorder;

class AiReconciliationJobObserver
{
    public function __construct(private readonly AiReconciliationAlertRecorder $recorder)
    {
    }

    public function updated(AiReconciliationJob $job): void
    {
        if ($job->wasChanged('status') && $job->status === 'FAILED') {
            $this->recorder->recordFailedJob($job);
        }
    }
}
