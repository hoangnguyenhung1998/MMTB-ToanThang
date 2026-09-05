<?php

namespace App\Services;

use App\Models\OcrJob;
use App\Models\OcrProcessingRun;
use Illuminate\Support\Str;

class OcrProcessingRunService
{
    public function start(OcrJob $job, string $workerId): OcrProcessingRun
    {
        $this->timeoutOpenRuns($job);

        return OcrProcessingRun::query()->create([
            'ocr_job_id' => $job->id,
            'worker_id' => $workerId,
            'stage' => $this->stageFor($job->document_type),
            'attempt' => $job->attempts,
            'status' => 'PROCESSING',
            'started_at' => now(),
        ]);
    }

    public function finish(OcrJob $job, string $workerId, string $status, ?string $error = null): void
    {
        $run = OcrProcessingRun::query()
            ->where('ocr_job_id', $job->id)
            ->where('worker_id', $workerId)
            ->where('status', 'PROCESSING')
            ->latest('id')
            ->first();

        if (! $run) {
            return;
        }

        $finishedAt = now();
        $run->update([
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, $run->started_at->diffInMilliseconds($finishedAt)),
            'error_message' => $error ? Str::limit($error, 2000, '') : null,
        ]);
    }

    private function timeoutOpenRuns(OcrJob $job): void
    {
        OcrProcessingRun::query()
            ->where('ocr_job_id', $job->id)
            ->where('status', 'PROCESSING')
            ->get()
            ->each(function (OcrProcessingRun $run): void {
                $finishedAt = now();
                $run->update([
                    'status' => 'TIMED_OUT',
                    'finished_at' => $finishedAt,
                    'duration_ms' => max(0, $run->started_at->diffInMilliseconds($finishedAt)),
                    'error_message' => 'Worker lease expired before completion.',
                ]);
            });
    }

    private function stageFor(string $documentType): string
    {
        return match ($documentType) {
            'DAILY_TIMEMARK' => 'TIMEMARK',
            'WEEKLY_JOURNAL' => 'JOURNAL',
            default => 'CLASSIFY',
        };
    }
}
