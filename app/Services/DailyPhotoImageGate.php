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
        $result = $this->classifyText($texts->implode("\n"));
        if ($result) {
            $result['gate_stage'] = 'BACKLOG_STORED_OCR';
        }

        return $result;
    }

    public function classifyText(string $text): ?array
    {
        $normalized = Str::upper(Str::ascii($text));
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
                ];
            }
        }

        $markers = collect([
            preg_match('/\bHOURS?\b/', $normalized) ? 'HOURS' : null,
            str_contains($normalized, 'HOUR METER') ? 'HOUR_METER' : null,
            str_contains($normalized, 'ENGINE HOURS') ? 'ENGINE_HOURS' : null,
        ])->filter()->unique()->values();
        preg_match_all('/(?<!\d)(?:\d{4,8}(?:[.,]\d)?|\d{1,3}(?:[ .]\d{3}){1,2}(?:[.,]\d)?)(?!\d)/', $normalized, $counterTokens);
        $counters = collect($counterTokens[0] ?? [])->filter(
            fn (string $token): bool => preg_match('/[.,]\d$/', str_replace(' ', '', $token)) === 1
                || strlen(preg_replace('/\D/', '', $token)) >= 5,
        )->values()->all();
        $decimalCounter = collect($counters)->contains(
            fn (string $token): bool => preg_match('/[.,]\d$/', str_replace(' ', '', $token)) === 1,
        );
        $tenthsMarker = preg_match('/(?<!\d)1\s*\/\s*10(?!\d)/', $normalized) === 1;
        $meterContext = $markers->contains(fn (string $marker): bool => in_array($marker, ['HOUR_METER', 'ENGINE_HOURS'], true));
        $hourLabel = $markers->contains('HOURS') || $meterContext;
        $semanticSignals = $markers->count() + (int) $decimalCounter + (int) $tenthsMarker;
        if ($hourLabel && $counters !== [] && $semanticSignals >= 2) {
            return [
                'document_type' => 'IGNORED_HOUR_METER',
                'confidence' => 0.90,
                'reason' => 'MULTI_SIGNAL_HOUR_METER',
                'semantic_markers' => $markers->all(),
                'counter_token_count' => count($counters),
                'decimal_counter' => $decimalCounter,
                'tenths_marker' => $tenthsMarker,
            ];
        }

        return null;
    }
}
