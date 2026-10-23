<?php

namespace App\Console\Commands;

use App\Models\DailyPhotoAiRescueRun;
use App\Services\DailyPhotoAiRescueRunService;
use Illuminate\Console\Command;

class ProcessDailyPhotoAiRescueRuns extends Command
{
    protected $signature = 'ocr:process-ai-rescue-runs {--steps=50} {--run=} {--retry}';

    protected $description = 'Enqueue confirmed AI Rescue selections in bounded durable units';

    public function handle(DailyPhotoAiRescueRunService $service): int
    {
        if ($this->option('retry')) {
            if (! $this->option('run')) {
                $this->error('--retry requires --run');

                return self::INVALID;
            }
            $service->retry(DailyPhotoAiRescueRun::findOrFail($this->option('run')));
        }
        $start = hrtime(true);
        for ($i = 0; $i < max(1, min(100, (int) $this->option('steps'))); $i++) {
            if (! $service->processNext($this->option('run')) || (hrtime(true) - $start) / 1e9 >= 15) {
                break;
            }
        }

        return $this->option('run') && DailyPhotoAiRescueRun::findOrFail($this->option('run'))->status === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }
}
