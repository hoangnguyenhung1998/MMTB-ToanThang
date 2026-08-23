<?php

namespace App\Services;

use App\Models\AiReconciliationJob;
use App\Models\AiReconciliationSubmission;
use App\Models\JournalRow;
use App\Models\OcrJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RuleReconciliationService
{
    private const DAILY_REVIEW_STATUSES = ['AUTO_APPROVED', 'APPROVED', 'CORRECTED'];

    private const JOURNAL_REVIEW_STATUSES = ['APPROVED', 'CORRECTED'];

    public function reconcilePending(string $workDate): void
    {
        AiReconciliationJob::query()
            ->whereDate('work_date', $workDate)
            ->whereIn('status', ['PENDING', 'RETRY'])
            ->oldest('id')
            ->eachById(fn (AiReconciliationJob $job) => $this->reconcile($job));
    }

    public function reconcile(AiReconciliationJob $job): ?AiReconciliationSubmission
    {
        $submissionUuid = $this->submissionUuid($job);
        $existing = AiReconciliationSubmission::query()
            ->where('submission_uuid', $submissionUuid)
            ->first();

        if ($existing) {
            return $existing->load('findings');
        }

        $dailyJobs = $this->dailyJobs($job);
        $journalRows = $this->journalRows($job);
        $result = $this->evaluate($job, $dailyJobs, $journalRows);

        // Missing evidence needs contextual analysis by OpenClaw in Phase 14.3.
        if ($result['outcome'] === 'INSUFFICIENT') {
            return null;
        }

        return DB::transaction(function () use ($job, $submissionUuid, $result): AiReconciliationSubmission {
            $lockedJob = AiReconciliationJob::query()->lockForUpdate()->findOrFail($job->id);
            $existing = AiReconciliationSubmission::query()
                ->where('submission_uuid', $submissionUuid)
                ->first();
            if ($existing) {
                return $existing->load('findings');
            }

            $submission = $lockedJob->submissions()->create([
                'submission_uuid' => $submissionUuid,
                'outcome' => $result['outcome'],
                'summary' => $result['summary'],
                'confidence' => $result['confidence'],
                'agent_name' => 'mmtb-rules-engine',
                'model' => null,
                'metadata' => [
                    'rules_version' => config('openclaw.rules.version'),
                    'source_signature' => $job->source_signature,
                    'matches' => $result['matches'],
                ],
                'submitted_at' => now(),
            ]);

            foreach ($result['findings'] as $finding) {
                $submission->findings()->create($finding);
            }

            $lockedJob->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'failed_at' => null,
                'error_message' => null,
            ]);

            return $submission->load('findings');
        }, 3);
    }

    private function evaluate(AiReconciliationJob $job, Collection $dailyJobs, Collection $journalRows): array
    {
        $findings = [];
        $matches = [];

        if ($journalRows->isEmpty()) {
            return $this->insufficient('JOURNAL_ROW_MISSING', 'Thiếu dòng nhật trình', [
                'daily_image_count' => $dailyJobs->count(),
                'journal_row_count' => 0,
            ]);
        }

        if ($dailyJobs->isEmpty()) {
            return $this->insufficient('DAILY_IMAGE_MISSING', 'Thiếu ảnh hằng ngày', [
                'daily_image_count' => 0,
                'journal_row_count' => $journalRows->count(),
            ]);
        }

        $duplicateGroups = $dailyJobs->groupBy('attachment.sha256')
            ->filter(fn ($group): bool => $group->count() > 1);
        foreach ($duplicateGroups as $sha256 => $duplicates) {
            $findings[] = $this->finding(
                'DUPLICATE_IMAGE',
                'CRITICAL',
                'Phát hiện ảnh trùng',
                ['sha256' => $sha256, 'ocr_job_ids' => $duplicates->pluck('id')->all()],
            );
        }

        foreach ($dailyJobs as $dailyJob) {
            if (mb_strtoupper(trim((string) $dailyJob->asset_code)) !== mb_strtoupper(trim($job->machine->asset_code))) {
                $findings[] = $this->finding(
                    'ASSET_CODE_MISMATCH',
                    'CRITICAL',
                    'Mã máy trên ảnh không khớp',
                    [
                        'ocr_job_id' => $dailyJob->id,
                        'expected_asset_code' => $job->machine->asset_code,
                        'extracted_asset_code' => $dailyJob->asset_code,
                    ],
                );
            }
        }

        $availableImages = $dailyJobs->keyBy('id');
        $hasMissingBoundary = false;

        foreach ($journalRows as $row) {
            $start = $this->dateTime($row->work_date->format('Y-m-d'), $row->start_time);
            $end = $this->dateTime($row->work_date->format('Y-m-d'), $row->end_time);
            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->addDay();
            }

            $startImage = $this->nearestImage($availableImages, $start);
            if ($startImage) {
                $availableImages->forget($startImage->id);
            }
            $endImage = $this->nearestImage($availableImages, $end);
            if ($endImage) {
                $availableImages->forget($endImage->id);
            }

            if (! $startImage || ! $endImage) {
                $hasMissingBoundary = true;
                if (! $startImage) {
                    $findings[] = $this->finding(
                        'START_IMAGE_MISSING',
                        'WARNING',
                        'Thiếu ảnh đầu ca',
                        ['journal_row_id' => $row->id],
                    );
                }
                if (! $endImage) {
                    $findings[] = $this->finding(
                        'END_IMAGE_MISSING',
                        'WARNING',
                        'Thiếu ảnh cuối ca',
                        ['journal_row_id' => $row->id],
                    );
                }
                continue;
            }

            $startImageAt = $this->imageDateTime($startImage);
            $endImageAt = $this->imageDateTime($endImage);
            $startDifference = (int) round(abs($start->diffInMinutes($startImageAt, false)));
            $endDifference = (int) round(abs($end->diffInMinutes($endImageAt, false)));
            $actualMinutes = (int) round($startImageAt->diffInMinutes($endImageAt));
            $durationDifference = abs($actualMinutes - (int) $row->total_minutes);

            $matches[] = [
                'journal_row_id' => $row->id,
                'start_ocr_job_id' => $startImage->id,
                'end_ocr_job_id' => $endImage->id,
                'start_difference_minutes' => $startDifference,
                'end_difference_minutes' => $endDifference,
                'duration_difference_minutes' => $durationDifference,
            ];

            $maximumDifference = max($startDifference, $endDifference, $durationDifference);
            if ($maximumDifference > (int) config('openclaw.rules.warning_minutes')) {
                $severity = $maximumDifference > (int) config('openclaw.rules.critical_minutes')
                    ? 'CRITICAL'
                    : 'WARNING';
                $findings[] = $this->finding('TIME_DIFFERENCE', $severity, 'Thời gian ảnh và nhật trình bị lệch', [
                    'journal_row_id' => $row->id,
                    'start_difference_minutes' => $startDifference,
                    'end_difference_minutes' => $endDifference,
                    'duration_difference_minutes' => $durationDifference,
                ]);
            }
        }

        if ($hasMissingBoundary) {
            return [
                'outcome' => 'INSUFFICIENT',
                'summary' => 'Thiếu ảnh đầu hoặc cuối ca; cần OpenClaw phân tích thêm.',
                'confidence' => null,
                'findings' => $findings,
                'matches' => $matches,
            ];
        }

        $outcome = collect($findings)->contains(fn (array $finding) => $finding['severity'] === 'CRITICAL')
            ? 'EXCEPTION'
            : (empty($findings) ? 'MATCHED' : 'WARNING');

        return [
            'outcome' => $outcome,
            'summary' => match ($outcome) {
                'MATCHED' => 'Ảnh hằng ngày khớp với nhật trình.',
                'WARNING' => 'Dữ liệu có chênh lệch cần kiểm tra.',
                default => 'Dữ liệu có ngoại lệ nghiêm trọng.',
            },
            'confidence' => empty($findings) ? 1.0 : 0.95,
            'findings' => $findings,
            'matches' => $matches,
        ];
    }

    private function dailyJobs(AiReconciliationJob $job): Collection
    {
        $hasOvernightRow = $this->journalRows($job)->contains(
            fn (JournalRow $row) => $row->end_time <= $row->start_time,
        );

        return OcrJob::query()
            ->with('attachment')
            ->where('document_type', 'DAILY_TIMEMARK')
            ->where('machine_id', $job->machine_id)
            ->whereIn('review_status', self::DAILY_REVIEW_STATUSES)
            ->where(function ($query) use ($job, $hasOvernightRow): void {
                $query->whereDate('extracted_date', $job->work_date);
                if ($hasOvernightRow) {
                    $query->orWhereDate('extracted_date', $job->work_date->copy()->addDay());
                }
            })
            ->orderBy('extracted_date')
            ->orderBy('extracted_time')
            ->get();
    }

    private function journalRows(AiReconciliationJob $job): Collection
    {
        return JournalRow::query()
            ->whereDate('work_date', $job->work_date)
            ->whereHas('document', fn ($query) => $query->where('machine_id', $job->machine_id))
            ->whereHas('document.ocrJob', fn ($query) => $query
                ->whereIn('review_status', self::JOURNAL_REVIEW_STATUSES))
            ->orderBy('start_time')
            ->get();
    }

    private function nearestImage(Collection $images, CarbonImmutable $target): ?OcrJob
    {
        $maximum = (int) config('openclaw.rules.match_window_minutes');

        return $images
            ->map(fn (OcrJob $job): array => [
                'job' => $job,
                'difference' => (int) round(abs($target->diffInMinutes($this->imageDateTime($job), false))),
            ])
            ->filter(fn (array $candidate): bool => $candidate['difference'] <= $maximum)
            ->sortBy('difference')
            ->value('job');
    }

    private function imageDateTime(OcrJob $job): CarbonImmutable
    {
        return $this->dateTime($job->extracted_date->format('Y-m-d'), $job->extracted_time);
    }

    private function dateTime(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", config('app.timezone'));
    }

    private function insufficient(string $code, string $title, array $evidence): array
    {
        return [
            'outcome' => 'INSUFFICIENT',
            'summary' => $title.'; cần OpenClaw phân tích thêm.',
            'confidence' => null,
            'findings' => [$this->finding($code, 'WARNING', $title, $evidence)],
            'matches' => [],
        ];
    }

    private function finding(string $code, string $severity, string $title, array $evidence): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'title' => $title,
            'evidence' => $evidence,
            'confidence' => 1.0,
        ];
    }

    private function submissionUuid(AiReconciliationJob $job): string
    {
        $hash = hash('sha256', implode('|', [
            config('openclaw.rules.version'),
            $job->id,
            $job->source_signature,
        ]));

        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec($hash[16]) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }
}
