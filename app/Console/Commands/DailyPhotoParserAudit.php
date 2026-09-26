<?php

namespace App\Console\Commands;

use App\Services\DailyPhotoParserAuditService;
use Illuminate\Console\Command;

class DailyPhotoParserAudit extends Command
{
    protected $signature = 'ocr:daily-parser-audit
        {--limit=0 : Maximum jobs to scan; 0 scans all}
        {--sample-limit=20 : Maximum categorized samples to print}';

    protected $description = 'Read-only audit of Daily TimeMark parser, source priority, mapping and stale state';

    public function handle(DailyPhotoParserAuditService $service): int
    {
        $report = $service->audit(max(0, (int) $this->option('limit')), max(0, (int) $this->option('sample-limit')));
        $this->info('Scanned: '.$report['scanned']);
        $this->table(['Category', 'Jobs'], collect($report['counts'])->map(
            fn (int $count, string $category): array => [$category, $count],
        )->values()->all());
        foreach ($report['samples'] as $sample) {
            $this->line(json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
