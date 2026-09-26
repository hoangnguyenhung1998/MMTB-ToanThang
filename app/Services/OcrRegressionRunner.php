<?php

namespace App\Services;

use App\Models\OcrRegressionCase;

class OcrRegressionRunner
{
    public function __construct(private readonly OcrRegressionEvaluator $evaluator) {}

    public function run(int $sampleLimit = 20): array
    {
        $summary = ['cases' => 0, 'pass' => 0, 'fail' => 0, 'by_category' => [], 'failures' => []];
        OcrRegressionCase::query()->where('verification_status', 'VERIFIED')->orderBy('id')
            ->chunkById(200, function ($cases) use (&$summary, $sampleLimit): void {
                foreach ($cases as $case) {
                    $result = $this->evaluator->evaluate($case);
                    $summary['cases']++;
                    $summary[$result['pass'] ? 'pass' : 'fail']++;
                    $category = $case->case_category;
                    $summary['by_category'][$category] ??= ['pass' => 0, 'total' => 0];
                    $summary['by_category'][$category]['total']++;
                    $summary['by_category'][$category]['pass'] += (int) $result['pass'];
                    if (! $result['pass'] && count($summary['failures']) < $sampleLimit) {
                        $summary['failures'][] = [
                            'case_id' => $case->id,
                            'category' => $category,
                            ...$result,
                        ];
                    }
                }
            });

        ksort($summary['by_category']);

        return $summary;
    }
}
