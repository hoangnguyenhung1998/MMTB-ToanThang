<?php

namespace App\Services;

use App\Models\OcrJob;
use DateTimeImmutable;
use Illuminate\Support\Str;

class DailyPhotoStoredOcrExtractor
{
    private const MONTHS = [
        'JAN' => 1, 'JANUARY' => 1,
        'FEB' => 2, 'FEBRUARY' => 2,
        'MAR' => 3, 'MARCH' => 3,
        'APR' => 4, 'APRIL' => 4,
        'MAY' => 5,
        'JUN' => 6, 'JUNE' => 6,
        'JUL' => 7, 'JULY' => 7,
        'AUG' => 8, 'AUGUST' => 8,
        'SEP' => 9, 'SEPT' => 9, 'SEPTEMBER' => 9,
        'OCT' => 10, 'OCTOBER' => 10,
        'NOV' => 11, 'NOVEMBER' => 11,
        'DEC' => 12, 'DECEMBER' => 12,
    ];

    public function __construct(private readonly AssetCodeResolver $assetCodes) {}

    public function extract(OcrJob $job): array
    {
        $payloads = collect([
            ['source' => 'persisted', 'raw_text' => $job->raw_text],
            ['source' => 'initial', 'raw_text' => data_get($job->ocr_initial_extraction, 'raw_text')],
            ['source' => 'retry', 'raw_text' => data_get($job->daily_metadata, 'ocr_recovery.retry_extraction.raw_text')],
        ])->filter(fn (array $payload): bool => filled($payload['raw_text']))
            ->unique('raw_text')
            ->values();

        $dates = collect();
        $times = collect();
        $ambiguousDates = collect();
        $machineCandidates = collect([
            $job->observed_asset_code,
            data_get($job->ocr_initial_extraction, 'asset_code'),
            data_get($job->daily_metadata, 'ocr_recovery.retry_extraction.asset_code'),
        ])->filter();

        foreach ($payloads as $payload) {
            foreach ($this->sections((string) $payload['raw_text']) as $section) {
                $parsed = $this->parseText($section['text'], $section['region'] === 'time_date');
                $dates->push(...$parsed['dates']);
                $times->push(...$parsed['times']);
                $ambiguousDates->push(...$parsed['ambiguous_dates']);
                $machineCandidates->push(...$this->machineCandidates($section['text']));
            }
        }

        $machines = $machineCandidates->map(fn (mixed $candidate): array => $this->assetCodes->resolve($candidate))
            ->filter(fn (array $result): bool => $result['status'] === 'MATCHED')
            ->map(fn (array $result): string => $result['machine']->asset_code)
            ->unique()->values();
        $dates = $dates->unique()->sort()->values();
        $times = $times->unique()->sort()->values();

        return [
            'machine_candidates' => $machines->all(),
            'date_candidates' => $dates->all(),
            'time_candidates' => $times->all(),
            'ambiguous_date_tokens' => $ambiguousDates->unique()->values()->all(),
            'machine' => $machines->count() === 1 ? $machines->first() : null,
            'date' => $dates->count() === 1 && $ambiguousDates->isEmpty() ? $dates->first() : null,
            'time' => $times->count() === 1 ? $times->first() : null,
            'machine_conflict' => $machines->count() > 1,
            'date_conflict' => $dates->count() > 1 || $ambiguousDates->isNotEmpty(),
            'time_conflict' => $times->count() > 1,
            'sources' => $payloads->pluck('source')->all(),
        ];
    }

    public function parseText(string $text, bool $trustedTimeRegion = false): array
    {
        $normalized = Str::upper(Str::ascii($text));
        $dates = collect();
        $times = collect();
        $ambiguousDates = collect();

        preg_match_all('/(?<!\d)(20\d{2})\s*[-\/.]\s*(\d{1,2})\s*[-\/.]\s*(\d{1,2})(?!\d)/', $normalized, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $this->pushDate($dates, (int) $match[1], (int) $match[2], (int) $match[3]);
        }

        preg_match_all('/(?<!\d)(\d{1,2})\s*[-\/.]\s*(\d{1,2})\s*[-\/.]\s*(20\d{2})(?!\d)/', $normalized, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            [$first, $second, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];
            if ($first > 12 && $second <= 12) {
                $this->pushDate($dates, $year, $second, $first);
            } elseif ($second > 12 && $first <= 12) {
                $this->pushDate($dates, $year, $first, $second);
            } elseif ($first === $second && $first >= 1 && $first <= 12) {
                $this->pushDate($dates, $year, $second, $first);
            } elseif ($first >= 1 && $first <= 12 && $second >= 1 && $second <= 12) {
                $ambiguousDates->push($match[0]);
            }
        }

        preg_match_all('/(?<!\d)(\d{1,2})\s+(JAN(?:UARY)?|FEB(?:RUARY)?|MAR(?:CH)?|APR(?:IL)?|MAY|JUN(?:E)?|JUL(?:Y)?|AUG(?:UST)?|SEP(?:T(?:EMBER)?)?|OCT(?:OBER)?|NOV(?:EMBER)?|DEC(?:EMBER)?)\s*,?\s*(20\d{2})/', $normalized, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $this->pushDate($dates, (int) $match[3], self::MONTHS[$match[2]], (int) $match[1]);
        }

        preg_match_all('/(?<!\d)(\d{1,2})\s+THA(?:N|M)G\s+(\d{1,2})(?:\s+NAM)?\s*,?\s*(20\d{2})/', $normalized, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $this->pushDate($dates, (int) $match[3], (int) $match[2], (int) $match[1]);
        }

        $withoutDates = preg_replace([
            '/(?<!\d)20\d{2}\s*[-\/.]\s*\d{1,2}\s*[-\/.]\s*\d{1,2}(?!\d)/',
            '/(?<!\d)\d{1,2}\s*[-\/.]\s*\d{1,2}\s*[-\/.]\s*20\d{2}(?!\d)/',
        ], ' ', $normalized);
        $separator = $trustedTimeRegion ? '[:.H-]' : '[:.H]';
        preg_match_all('/(?<![\d.])([01]?\d|2[0-3])\s*'.$separator.'\s*([0-5]\d)(?:\s*([AP])\s*\.?\s*M\.?)?(?![\d.])/', $withoutDates, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $hour = (int) $match[1];
            if (filled($match[3] ?? null)) {
                if ($hour < 1 || $hour > 12) {
                    continue;
                }
                $hour = $hour % 12 + (Str::upper($match[3]) === 'P' ? 12 : 0);
            }
            $times->push(sprintf('%02d:%02d:00', $hour, (int) $match[2]));
        }

        return [
            'dates' => $dates->unique()->values()->all(),
            'times' => $times->unique()->values()->all(),
            'ambiguous_dates' => $ambiguousDates->unique()->values()->all(),
        ];
    }

    private function sections(string $raw): array
    {
        $parts = preg_split('/\[(\d+)deg\/([a-z_]+)\]\R/i', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts) || count($parts) < 4) {
            return [['region' => 'unknown', 'text' => $raw]];
        }

        $sections = [];
        for ($index = 1; $index + 2 < count($parts); $index += 3) {
            $sections[] = ['region' => Str::lower($parts[$index + 1]), 'text' => $parts[$index + 2]];
        }

        return $sections;
    }

    private function machineCandidates(string $text): array
    {
        preg_match_all('/[A-Z0-9]{1,4}\s*[-_ ]\s*[A-Z0-9]{1,4}\s*[-_ ]?\s*[A-Z0-9]{2,8}/i', $text, $matches);

        return $matches[0] ?? [];
    }

    private function pushDate($dates, int $year, int $month, int $day): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-n-j', "{$year}-{$month}-{$day}");
        if ($date && $date->format('Y-n-j') === "{$year}-{$month}-{$day}") {
            $dates->push($date->format('Y-m-d'));
        }
    }
}
