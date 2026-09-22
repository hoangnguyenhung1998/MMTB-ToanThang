<?php

namespace App\Services;

use App\Models\OcrJob;
use Illuminate\Support\Str;

class DailyPhotoImageGate
{
    public function classifyStored(OcrJob $job): ?array
    {
        $existing = data_get($job->daily_metadata, 'image_classification');
        if (is_array($existing) && in_array($existing['document_type'] ?? null, [
            'IGNORED_HOUR_METER',
            'IGNORED_NON_DAILY_PHOTO',
        ], true)) {
            return $existing;
        }

        $texts = collect([
            $job->raw_text,
            data_get($job->ocr_initial_extraction, 'raw_text'),
            data_get($job->daily_metadata, 'ocr_recovery.retry_extraction.raw_text'),
            data_get($job->daily_metadata, 'ocr_recovery.final_chosen_result.raw_text'),
        ])->filter()->unique();
        $normalized = Str::upper(Str::ascii($texts->implode("\n")));
        if ($normalized === '') {
            return null;
        }

        foreach (['BIEN BAN BAN GIAO', 'HOA DON', 'PHIEU XUAT KHO', 'BIEN BAN NGHIEM THU'] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return [
                    'document_type' => 'IGNORED_NON_DAILY_PHOTO',
                    'confidence' => 0.99,
                    'reason' => 'KNOWN_NON_DAILY_DOCUMENT',
                    'matched_phrase' => $phrase,
                    'gate_stage' => 'BACKLOG_STORED_OCR',
                ];
            }
        }

        $markers = collect([
            str_contains($normalized, 'QUARTZ') ? 'QUARTZ' : null,
            preg_match('/\bHOURS?\b/', $normalized) ? 'HOURS' : null,
            str_contains($normalized, 'HOUR METER') ? 'HOUR_METER' : null,
            str_contains($normalized, 'ENGINE HOURS') ? 'ENGINE_HOURS' : null,
        ])->filter()->unique()->values();
        preg_match_all('/(?<!\d)\d(?:[ .]?\d){3,7}(?:[.,]\d)?(?!\d)/', $normalized, $counterTokens);
        if ($markers->count() >= 2 && count($counterTokens[0] ?? []) >= 1) {
            return [
                'document_type' => 'IGNORED_HOUR_METER',
                'confidence' => 0.90,
                'reason' => 'MULTI_SIGNAL_HOUR_METER',
                'semantic_markers' => $markers->all(),
                'counter_token_count' => count($counterTokens[0]),
                'gate_stage' => 'BACKLOG_STORED_OCR',
            ];
        }

        return null;
    }
}
