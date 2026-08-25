<?php

namespace App\Services;

use App\Models\AiReconciliationAlert;
use App\Models\AiReconciliationJob;
use App\Models\AiReconciliationSubmission;

class AiReconciliationAlertRecorder
{
    public function recordSubmission(AiReconciliationSubmission $submission): ?AiReconciliationAlert
    {
        if (! in_array($submission->outcome, ['WARNING', 'EXCEPTION'], true)) {
            return null;
        }

        $submission->loadMissing('job.machine:id,asset_code');
        $job = $submission->job;

        return AiReconciliationAlert::query()->firstOrCreate(
            ['fingerprint' => "submission:{$submission->id}:{$submission->outcome}"],
            [
                'ai_reconciliation_job_id' => $job->id,
                'ai_reconciliation_submission_id' => $submission->id,
                'kind' => $submission->outcome,
                'severity' => $submission->outcome === 'EXCEPTION' ? 'CRITICAL' : 'WARNING',
                'payload' => $this->jobPayload($job, [
                    'outcome' => $submission->outcome,
                    'summary' => $submission->summary,
                    'confidence' => $submission->confidence,
                ]),
            ],
        );
    }

    public function recordFailedJob(AiReconciliationJob $job): AiReconciliationAlert
    {
        $job->loadMissing('machine:id,asset_code');
        $failureKey = implode(':', [$job->source_signature, $job->attempts]);

        return AiReconciliationAlert::query()->firstOrCreate(
            ['fingerprint' => "job:{$job->id}:failed:".hash('sha256', $failureKey)],
            [
                'ai_reconciliation_job_id' => $job->id,
                'kind' => 'FAILED',
                'severity' => 'CRITICAL',
                'payload' => $this->jobPayload($job, [
                    'error' => $job->error_message,
                ]),
            ],
        );
    }

    public function recordWaitingJob(AiReconciliationJob $job): AiReconciliationAlert
    {
        $job->loadMissing('machine:id,asset_code');

        return AiReconciliationAlert::query()->firstOrCreate(
            ['fingerprint' => "job:{$job->id}:waiting:{$job->source_signature}"],
            [
                'ai_reconciliation_job_id' => $job->id,
                'kind' => 'WAITING_EVIDENCE',
                'severity' => 'WARNING',
                'payload' => $this->jobPayload($job, [
                    'reason' => $job->error_message,
                ]),
            ],
        );
    }

    private function jobPayload(AiReconciliationJob $job, array $details): array
    {
        return array_merge([
            'job_id' => $job->id,
            'asset_code' => $job->machine?->asset_code ?? 'Không rõ',
            'work_date' => $job->work_date?->format('d/m/Y'),
            'dashboard_url' => $this->dashboardUrl($job),
        ], $details);
    }

    private function dashboardUrl(AiReconciliationJob $job): string
    {
        $baseUrl = config('mmtb.reconciliation_alerts.dashboard_url')
            ?: rtrim((string) config('app.url'), '/').'/ai-reconciliation';

        return rtrim($baseUrl, '/').'/'.$job->id;
    }
}
