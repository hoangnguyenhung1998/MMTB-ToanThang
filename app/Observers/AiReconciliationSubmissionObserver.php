<?php

namespace App\Observers;

use App\Models\AiReconciliationSubmission;
use App\Services\AiReconciliationAlertRecorder;

class AiReconciliationSubmissionObserver
{
    public function __construct(private readonly AiReconciliationAlertRecorder $recorder)
    {
    }

    public function created(AiReconciliationSubmission $submission): void
    {
        $this->recorder->recordSubmission($submission);
    }
}
