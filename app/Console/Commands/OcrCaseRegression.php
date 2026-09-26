<?php

namespace App\Console\Commands;

use App\Services\OcrRegressionRunner;
use Illuminate\Console\Command;

class OcrCaseRegression extends Command
{
    protected $signature = 'ocr:case-regression {--sample-limit=20 : Maximum failed cases to print}';

    protected $description = 'Run VERIFIED OCR cases through the deterministic read-only evaluator';

    public function handle(OcrRegressionRunner $runner): int
    {
        $result = $runner->run(max(0, (int) $this->option('sample-limit')));
        $this->info("Cases: {$result['cases']}");
        $this->line("PASS: {$result['pass']}");
        $this->line("FAIL: {$result['fail']}");
        $this->table(['Category', 'Result'], collect($result['by_category'])->map(
            fn (array $row, string $category): array => [$category, "{$row['pass']}/{$row['total']}"],
        )->values()->all());
        foreach ($result['failures'] as $failure) {
            $this->error(sprintf(
                'case_id=%d category=%s mismatch=%s expected=%s actual=%s',
                $failure['case_id'],
                $failure['category'],
                implode(',', $failure['mismatches']),
                json_encode($failure['expected'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($failure['actual'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));
        }

        return $result['fail'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
