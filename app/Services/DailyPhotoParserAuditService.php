<?php

namespace App\Services;

use App\Models\OcrJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DailyPhotoParserAuditService
{
    public const CATEGORIES = [
        'PRIMARY_HAS_DATE_BUT_FINAL_MISSING',
        'PRIMARY_HAS_TIME_BUT_FINAL_MISSING',
        'PRIMARY_HAS_MACHINE_BUT_FINAL_UNRESOLVED',
        'RAW_HAS_DATE_BUT_PARSER_MISSING',
        'RAW_HAS_TIME_BUT_PARSER_MISSING',
        'LOWER_SOURCE_INTRODUCED_DATE_CONFLICT',
        'LOWER_SOURCE_INTRODUCED_TIME_CONFLICT',
        'MACHINE_MAPPING_RECOVERABLE',
        'MAPPING_REQUIRED',
        'POSSIBLE_HOUR_METER',
        'COMPLETE_FIELDS_BUT_STILL_EXCEPTION',
        'TRUE_SAME_TIER_DATE_CONFLICT',
        'TRUE_SAME_TIER_TIME_CONFLICT',
        'TRUE_MACHINE_CONFLICT',
    ];

    public function __construct(
        private readonly DailyPhotoBacklogService $backlog,
        private readonly DailyPhotoImageGate $imageGate,
    ) {}

    public function audit(int $limit = 0, int $sampleLimit = 20): array
    {
        $counts = array_fill_keys(self::CATEGORIES, 0);
        $samples = [];
        $scanned = 0;
        $query = OcrJob::query()->where('document_type', 'DAILY_TIMEMARK')
            ->with(['attachment.message', 'machine:id,asset_code', 'dailyPhotoCaseEvidence'])
            ->orderByRaw("CASE status WHEN 'EXCEPTION' THEN 0 WHEN 'FAILED' THEN 1 ELSE 2 END")
            ->orderBy('id');

        $query->chunkById(200, function (Collection $jobs) use (&$counts, &$samples, &$scanned, $limit, $sampleLimit): bool {
            if ($limit > 0) {
                $jobs = $jobs->take(max(0, $limit - $scanned));
            }
            if ($jobs->isEmpty()) {
                return false;
            }
            $rows = $this->backlog->analyse($jobs);
            foreach ($rows as $row) {
                $job = $row['job'];
                $stored = $row['stored_extraction'];
                $categories = $this->categories($job, $stored, $row);
                $scanned++;
                foreach ($categories as $category) {
                    $counts[$category]++;
                    if (count($samples) < $sampleLimit) {
                        $samples[] = $this->sample($category, $job, $stored, $row);
                    }
                }
                if ($limit > 0 && $scanned >= $limit) {
                    return false;
                }
            }

            return true;
        }, 'id');

        return [
            'scanned' => $scanned,
            'counts' => array_filter($counts),
            'samples' => $samples,
        ];
    }

    private function categories(OcrJob $job, array $stored, array $row): array
    {
        $categories = collect();
        $primaryDate = $this->acceptedAtPriority($stored['date_evidence'] ?? [], 0);
        $primaryTime = $this->acceptedAtPriority($stored['time_evidence'] ?? [], 0);
        $primaryMachine = (int) data_get($stored, 'selected_priorities.machine', -1) === 0 && filled($stored['machine']);
        if ($primaryDate && ! $job->extracted_date) {
            $categories->push('PRIMARY_HAS_DATE_BUT_FINAL_MISSING');
        }
        if ($primaryTime && ! $job->extracted_time) {
            $categories->push('PRIMARY_HAS_TIME_BUT_FINAL_MISSING');
        }
        if ($primaryMachine && ! $job->machine_id) {
            $categories->push('PRIMARY_HAS_MACHINE_BUT_FINAL_UNRESOLVED');
        }
        if ($this->rawLooksLikeDate((string) $job->raw_text) && ! $stored['date']) {
            $categories->push('RAW_HAS_DATE_BUT_PARSER_MISSING');
        }
        if ($this->rawLooksLikeTime((string) $job->raw_text) && ! $stored['time']) {
            $categories->push('RAW_HAS_TIME_BUT_PARSER_MISSING');
        }
        if ($this->lowerTierDisagrees($stored['date_evidence'] ?? [])) {
            $categories->push('LOWER_SOURCE_INTRODUCED_DATE_CONFLICT');
        }
        if ($this->lowerTierDisagrees($stored['time_evidence'] ?? [])) {
            $categories->push('LOWER_SOURCE_INTRODUCED_TIME_CONFLICT');
        }
        if ($row['diagnostic_subtype'] === 'MAPPING_RECOVERABLE') {
            $categories->push('MACHINE_MAPPING_RECOVERABLE');
        }
        if ($row['diagnostic_subtype'] === 'MAPPING_MISSING') {
            $categories->push('MAPPING_REQUIRED');
        }
        if (($this->imageGate->classifyStored($job)['document_type'] ?? null) === 'IGNORED_HOUR_METER') {
            $categories->push('POSSIBLE_HOUR_METER');
        }
        if ($job->status === 'EXCEPTION' && $row['machine'] && $row['recovered_date'] && $row['recovered_time'] && ! $row['candidate_conflict']) {
            $categories->push('COMPLETE_FIELDS_BUT_STILL_EXCEPTION');
        }
        if ($stored['date_conflict']) {
            $categories->push('TRUE_SAME_TIER_DATE_CONFLICT');
        }
        if ($stored['time_conflict']) {
            $categories->push('TRUE_SAME_TIER_TIME_CONFLICT');
        }
        if ($stored['machine_conflict']) {
            $categories->push('TRUE_MACHINE_CONFLICT');
        }

        return $categories->unique()->values()->all();
    }

    private function acceptedAtPriority(array $evidence, int $priority): bool
    {
        return collect($evidence)->contains(fn (mixed $item): bool => is_array($item)
            && ($item['accepted'] ?? false) === true && (int) ($item['priority'] ?? -1) === $priority);
    }

    private function lowerTierDisagrees(array $evidence): bool
    {
        $accepted = collect($evidence)->filter(fn (mixed $item): bool => is_array($item)
            && ($item['accepted'] ?? false) === true && filled($item['value'] ?? null));
        if ($accepted->isEmpty()) {
            return false;
        }
        $top = (int) $accepted->min('priority');
        $topValues = $accepted->where('priority', $top)->pluck('value')->unique();

        return $topValues->count() === 1
            && $accepted->where('priority', '>', $top)->pluck('value')->unique()->contains(
                fn (string $value): bool => $value !== $topValues->first(),
            );
    }

    private function rawLooksLikeDate(string $raw): bool
    {
        return preg_match('/\b\d{1,2}\s*(?:THA(?:NG|NIG|RIG)|SEP(?:T(?:EMBER)?)?)\s*,?\s*20\d{2}\b/i', Str::ascii($raw)) === 1;
    }

    private function rawLooksLikeTime(string $raw): bool
    {
        return preg_match('/(?<!\d)(?:[01]?\d|2[0-3])\s*[:.-]\s*[0-5]\d(?:1)?(?!\d)/', $raw) === 1;
    }

    private function sample(string $category, OcrJob $job, array $stored, array $row): array
    {
        return [
            'category' => $category,
            'job_id' => $job->id,
            'source_tier' => $stored['selected_priorities'],
            'raw' => Str::limit((string) $job->raw_text, 500),
            'normalized' => ['machine' => $stored['machine'], 'date' => $stored['date'], 'time' => $stored['time']],
            'final' => ['machine' => $job->machine?->asset_code, 'date' => $job->extracted_date?->format('Y-m-d'), 'time' => $job->extracted_time],
            'exceptions' => $job->exceptions,
            'machine_resolution_method' => $job->machine_resolution_method,
            'machine_resolution_provenance' => [
                'image_machine_id' => data_get($job->machine_resolution_metadata, 'image_asset_resolved_machine_id'),
                'sender_mapping_id' => data_get($job->machine_resolution_metadata, 'sender_machine_mapping_id'),
                'sender_machine_id' => data_get($job->machine_resolution_metadata, 'sender_resolution_machine_id'),
                'human_resolution' => data_get($job->machine_resolution_metadata, 'human_resolution'),
            ],
            'mapping_state' => $row['mapping']?->machine?->asset_code ?: ($row['has_effective_mapping'] ? 'AMBIGUOUS' : 'MISSING'),
            'protected' => $row['protected'],
            'human' => $job->machine_resolution_method === DailyPhotoMachineResolutionService::HUMAN,
        ];
    }
}
