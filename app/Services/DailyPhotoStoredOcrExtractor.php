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
        $snapshots = collect([
            'initial' => $job->ocr_initial_extraction ?? [],
            'retry' => data_get($job->daily_metadata, 'ocr_recovery.retry_extraction', []),
            'final' => data_get($job->daily_metadata, 'ocr_recovery.final_chosen_result', []),
        ]);
        $payloads = collect([
            ['source' => 'persisted', 'raw_text' => $job->raw_text],
            ...$snapshots->map(fn (mixed $snapshot, string $source): array => [
                'source' => $source,
                'raw_text' => is_array($snapshot) ? ($snapshot['raw_text'] ?? null) : null,
            ])->values()->all(),
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
            data_get($job->daily_metadata, 'ocr_recovery.final_chosen_result.asset_code'),
        ])->filter();
        $candidateOccurrences = ['machine' => 0, 'date' => 0, 'time' => 0];
        $legacyConflicts = collect();
        $dateEvidence = collect();
        $timeEvidence = collect();

        foreach ($payloads as $payload) {
            foreach ($this->sections((string) $payload['raw_text']) as $section) {
                $parsed = $this->parseText($section['text'], $section['region']);
                $candidateOccurrences['date'] += count($parsed['dates']);
                $candidateOccurrences['time'] += count($parsed['times']);
                $dates->push(...$parsed['dates']);
                $times->push(...$parsed['times']);
                $ambiguousDates->push(...$parsed['ambiguous_dates']);
                $dateEvidence->push(...collect($parsed['date_evidence'] ?? [])->map(fn (array $item): array => [
                    ...$item, 'source' => $payload['source'], 'rotation' => $section['rotation'], 'region' => $section['region'],
                ]));
                $timeEvidence->push(...collect($parsed['time_evidence'] ?? [])->map(fn (array $item): array => [
                    ...$item, 'source' => $payload['source'], 'rotation' => $section['rotation'], 'region' => $section['region'],
                ]));
                $sectionMachines = $this->machineCandidates($section['text']);
                $candidateOccurrences['machine'] += count($sectionMachines);
                $machineCandidates->push(...$sectionMachines);
            }
        }

        $candidateMetadata = collect([
            data_get($job->daily_metadata, 'ocr_candidate_summary', []),
            data_get($job->ocr_initial_extraction, 'candidate_metadata', []),
            data_get($job->daily_metadata, 'ocr_recovery.retry_extraction.candidate_metadata', []),
            data_get($job->daily_metadata, 'ocr_recovery.final_chosen_result.candidate_metadata', []),
        ])->filter(fn (mixed $metadata): bool => is_array($metadata) && $metadata !== []);
        foreach ($candidateMetadata as $metadata) {
            $metadataMachines = collect($metadata['machine_candidates'] ?? [])->filter();
            $metadataDates = collect($metadata['date_candidates'] ?? [])
                ->map(fn (mixed $value): ?string => $this->normalizeDate($value))->filter();
            $metadataTimes = collect($metadata['time_candidates'] ?? [])
                ->map(fn (mixed $value): ?string => $this->normalizeTime($value))->filter();
            $candidateOccurrences['machine'] = max($candidateOccurrences['machine'], $metadataMachines->count());
            $candidateOccurrences['date'] = max($candidateOccurrences['date'], $metadataDates->count());
            $candidateOccurrences['time'] = max($candidateOccurrences['time'], $metadataTimes->count());
            $machineCandidates->push(...$metadataMachines);
            $dates->push(...$metadataDates);
            $times->push(...$metadataTimes);
            $legacyConflicts->push(...collect($metadata['conflicts'] ?? [])
                ->filter(fn (mixed $field): bool => in_array($field, ['machine', 'date', 'time'], true)));
            $dateEvidence->push(...collect($metadata['date_evidence'] ?? [])->filter(fn ($item): bool => is_array($item)));
            $timeEvidence->push(...collect($metadata['time_evidence'] ?? [])->filter(fn ($item): bool => is_array($item)));
        }

        // Structured retry values are authoritative supplements for fields that
        // are still absent. They must not compete with an already-persisted
        // deterministic value from the initial pass.
        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }
            if (! $job->extracted_date && ($date = $this->normalizeDate($snapshot['date'] ?? null))) {
                $dates->push($date);
            }
            if (! $job->extracted_time && ($time = $this->normalizeTime($snapshot['time'] ?? null))) {
                $times->push($time);
            }
        }

        $machines = $machineCandidates->map(fn (mixed $candidate): array => $this->assetCodes->resolve($candidate))
            ->filter(fn (array $result): bool => $result['status'] === 'MATCHED')
            ->map(fn (array $result): string => $result['machine']->asset_code)
            ->unique()->values();
        $receivedDate = $job->attachment?->message?->received_at?->toDateString();
        $discardedFutureDates = $dates->filter(fn (string $value): bool => $receivedDate && $value > $receivedDate)->unique()->sort()->values();
        $dates = $dates->reject(fn (string $value): bool => $receivedDate && $value > $receivedDate)->unique()->sort()->values();
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
            'legacy_conflicts' => $legacyConflicts->unique()->values()->all(),
            'duplicate_equivalent_fields' => collect($candidateOccurrences)
                ->filter(fn (int $count, string $field): bool => $count > match ($field) {
                    'machine' => $machines->count(),
                    'date' => $dates->count(),
                    'time' => $times->count(),
                } && match ($field) {
                    'machine' => $machines->count() === 1,
                    'date' => $dates->count() === 1 && $ambiguousDates->isEmpty(),
                    'time' => $times->count() === 1,
                })->keys()->values()->all(),
            'sources' => $payloads->pluck('source')->all(),
            'date_evidence' => $dateEvidence->values()->all(),
            'time_evidence' => $timeEvidence->values()->all(),
            'discarded_date_candidates' => $discardedFutureDates->all(),
        ];
    }

    public function parseText(string $text, bool|string $region = false): array
    {
        $region = is_bool($region) ? ($region ? 'time_date' : 'unknown') : $region;
        $trustedTimeRegion = $region === 'time_date';
        $normalized = Str::upper(Str::ascii($text));
        $dates = collect();
        $times = collect();
        $ambiguousDates = collect();
        $dateEvidence = collect();
        $timeEvidence = collect();

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

        preg_match_all('/(?<!\d)(\d{1,2})\s*THA(?:NG|NIG|RIG|MG)\s*(\d{1,2})(?:\s+NAM)?\s*,?\s*(20\d{2})/', $normalized, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $this->pushDate($dates, (int) $match[3], (int) $match[2], (int) $match[1]);
        }

        $withoutDates = preg_replace([
            '/(?<!\d)20\d{2}\s*[-\/.]\s*\d{1,2}\s*[-\/.]\s*\d{1,2}(?!\d)/',
            '/(?<!\d)\d{1,2}\s*[-\/.]\s*\d{1,2}\s*[-\/.]\s*20\d{2}(?!\d)/',
            '/(?<!\d)\d{1,2}\s*(?:JAN(?:UARY)?|FEB(?:RUARY)?|MAR(?:CH)?|APR(?:IL)?|MAY|JUN(?:E)?|JUL(?:Y)?|AUG(?:UST)?|SEP(?:T(?:EMBER)?)?|OCT(?:OBER)?|NOV(?:EMBER)?|DEC(?:EMBER)?)\s*,?\s*20\d{2}/',
            '/(?<!\d)\d{1,2}\s*THA(?:NG|NIG|RIG|MG)\s*\d{1,2}(?:\s+NAM)?\s*,?\s*20\d{2}/',
        ], ' ', $normalized);
        preg_match_all('/(?<![\d.])(?:[01]?\d|2[0-3])\s*[:.H]\s*[0-5]\d\s*(?:-|–|—|TO|DEN)\s*(?:[01]?\d|2[0-3])\s*[:.H]\s*[0-5]\d(?![\d.])/', $withoutDates, $intervals, PREG_SET_ORDER);
        foreach ($intervals as $interval) {
            $timeEvidence->push(['raw' => $interval[0], 'accepted' => false, 'reason' => 'WORK_INTERVAL']);
        }
        $withoutIntervals = preg_replace('/(?<![\d.])(?:[01]?\d|2[0-3])\s*[:.H]\s*[0-5]\d\s*(?:-|–|—|TO|DEN)\s*(?:[01]?\d|2[0-3])\s*[:.H]\s*[0-5]\d(?![\d.])/', ' ', $withoutDates);
        preg_match_all('/(?<!\d)\d{1,3}\s*(?:GIO|HOURS?)\s*\d{1,2}\s*(?:PHUT|MIN(?:UTE)?S?)/', $withoutIntervals, $durations, PREG_SET_ORDER);
        foreach ($durations as $duration) {
            $timeEvidence->push(['raw' => $duration[0], 'accepted' => false, 'reason' => 'DURATION']);
        }
        $captureContext = in_array($region, ['time_date', 'left_overlay'], true)
            || $dates->isNotEmpty()
            || Str::contains($normalized, ['TIMEMARK', 'TIME MARK', 'TAN CA']);
        $separator = $trustedTimeRegion ? '[:.H-]' : '[:.H]';
        preg_match_all('/(?<![\d.])([01]?\d|2[0-3])\s*'.$separator.'\s*([0-5]\d)(?:\s*([AP])\s*\.?\s*M\.?)?(?![\d.])/', $withoutIntervals, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $hour = (int) $match[1];
            if (filled($match[3] ?? null)) {
                if ($hour < 1 || $hour > 12) {
                    continue;
                }
                $hour = $hour % 12 + (Str::upper($match[3]) === 'P' ? 12 : 0);
            }
            $value = sprintf('%02d:%02d:00', $hour, (int) $match[2]);
            $timeEvidence->push([
                'raw' => $match[0], 'value' => $value, 'accepted' => $captureContext,
                'reason' => $captureContext ? ($trustedTimeRegion && str_contains($match[0], '-') ? 'TRUSTED_DASH' : 'STANDARD') : 'UNTRUSTED_CONTEXT',
            ]);
            if ($captureContext) {
                $times->push($value);
            }
        }
        if ($trustedTimeRegion) {
            preg_match_all('/(?<![\d.])([01]?\d|2[0-3])\s*:\s*([0-5]\d)1(?!\d)/', $withoutIntervals, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $value = sprintf('%02d:%02d:00', (int) $match[1], (int) $match[2]);
                $times->push($value);
                $timeEvidence->push(['raw' => $match[0], 'value' => $value, 'accepted' => true, 'reason' => 'TRAILING_TIMEMARK_ARTIFACT']);
            }
        }
        preg_match_all('/(?<!\d)\d{1,2}\s*[:.]\s*\d{2,3}(?!\d)/', $withoutIntervals, $invalidTimes, PREG_SET_ORDER);
        foreach ($invalidTimes as $invalid) {
            if (! preg_match('/^(?:[01]?\d|2[0-3])\s*[:.]\s*[0-5]\d(?:1)?$/', trim($invalid[0]))) {
                $timeEvidence->push(['raw' => $invalid[0], 'accepted' => false, 'reason' => 'INVALID_TIME']);
            }
        }

        $dateEvidence = $dates->map(fn (string $value): array => ['value' => $value, 'accepted' => true, 'reason' => 'PARSED']);

        return [
            'dates' => $dates->unique()->values()->all(),
            'times' => $times->unique()->values()->all(),
            'ambiguous_dates' => $ambiguousDates->unique()->values()->all(),
            'date_evidence' => $dateEvidence->values()->all(),
            'time_evidence' => $timeEvidence->values()->all(),
        ];
    }

    private function sections(string $raw): array
    {
        $parts = preg_split('/\[(\d+)deg\/([a-z_]+)\]\R/i', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts) || count($parts) < 4) {
            return [['rotation' => null, 'region' => 'unknown', 'text' => $raw]];
        }

        $sections = [];
        for ($index = 1; $index + 2 < count($parts); $index += 3) {
            $sections[] = ['rotation' => (int) $parts[$index], 'region' => Str::lower($parts[$index + 1]), 'text' => $parts[$index + 2]];
        }

        return $sections;
    }

    private function machineCandidates(string $text): array
    {
        preg_match_all('/[A-Z0-9]{1,4}\s*[-_ ]\s*[A-Z0-9]{1,4}\s*[-_ ]?\s*[A-Z0-9]{2,8}/i', $text, $matches);
        $observed = $matches[0] ?? [];
        $observedKeys = collect($observed)->map(fn (string $value): ?string => AssetCodeResolver::canonicalKey($value));
        $embeddedExact = collect($this->assetCodes->exactCatalogMatchesInText($text))
            ->reject(fn (string $value): bool => $observedKeys->contains(AssetCodeResolver::canonicalKey($value)));

        return array_values(array_unique([
            ...$observed,
            ...$embeddedExact,
        ]));
    }

    private function pushDate($dates, int $year, int $month, int $day): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-n-j', "{$year}-{$month}-{$day}");
        if ($date && $date->format('Y-n-j') === "{$year}-{$month}-{$day}") {
            $dates->push($date->format('Y-m-d'));
        }
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
            return null;
        }

        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
