<?php

namespace App\Services;

use App\Models\OcrRegressionCase;

class OcrRegressionEvaluator
{
    public function __construct(
        private readonly DailyPhotoStoredOcrExtractor $extractor,
        private readonly DailyPhotoImageGate $imageGate,
    ) {}

    public function evaluate(OcrRegressionCase $case): array
    {
        $snapshot = $case->input_snapshot ?? [];
        $classification = $this->imageGate->classifyText((string) ($snapshot['raw_text'] ?? ''));
        $disposition = $classification['document_type'] ?? 'DAILY_TIMEMARK';
        $parsed = $this->extractor->extractSnapshot($snapshot);
        $machine = null;
        $reason = null;

        if ($disposition === 'DAILY_TIMEMARK') {
            if ($parsed['machine_conflict']) {
                $reason = 'TRUE_MACHINE_CONFLICT';
            } elseif ($parsed['machine']) {
                $machine = $parsed['machine'];
            } elseif (filled($snapshot['mapping_asset_code'] ?? null)) {
                $machine = $snapshot['mapping_asset_code'];
            } else {
                $reason = 'MAPPING_REQUIRED';
            }
        }

        $actual = [
            'machine' => $machine,
            'date' => $disposition === 'DAILY_TIMEMARK' ? $parsed['date'] : null,
            'time' => $disposition === 'DAILY_TIMEMARK' ? $parsed['time'] : null,
            'disposition' => $disposition,
            'status' => $disposition !== 'DAILY_TIMEMARK'
                ? 'IGNORED'
                : ($machine && $parsed['date'] && $parsed['time'] && ! $parsed['date_conflict'] && ! $parsed['time_conflict'] ? 'AUTO' : 'EXCEPTION'),
            'reason' => $reason
                ?? ($parsed['date_conflict'] ? 'TRUE_DATE_CONFLICT' : null)
                ?? ($parsed['time_conflict'] ? 'TRUE_TIME_CONFLICT' : null),
        ];
        $expected = [
            'machine' => $case->expected_machine,
            'date' => $case->expected_date?->format('Y-m-d'),
            'time' => $case->expected_time,
            'disposition' => $case->expected_disposition,
            'status' => $case->expected_status,
        ];
        $fields = $case->expected_disposition === 'DAILY_TIMEMARK'
            ? ['machine', 'date', 'time', 'disposition', 'status']
            : ['disposition', 'status'];
        $mismatches = collect($fields)->filter(
            fn (string $field): bool => ($actual[$field] ?? null) !== ($expected[$field] ?? null),
        )->values()->all();

        return compact('expected', 'actual', 'mismatches') + ['pass' => $mismatches === []];
    }
}
