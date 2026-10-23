<?php

namespace App\Console\Commands;

use App\Models\DailyPhotoRecoveryRun;
use App\Services\DailyPhotoRecoveryRunService;
use Illuminate\Console\Command;

class ProcessDailyPhotoRecoveryRuns extends Command
{
    protected $signature = 'ocr:process-daily-recovery-runs {--steps=10 : Maximum atomic work units} {--run= : Only this run UUID} {--retry : Resume a FAILED run; requires --run}';

    protected $description = 'Process durable backlog recovery in bounded database transactions';

    public function handle(DailyPhotoRecoveryRunService $service): int
    {
        if ($this->option('retry')) {
            if (! $this->option('run')) {
                $this->error('--retry requires --run');

                return self::INVALID;
            }
            $service->retry(DailyPhotoRecoveryRun::findOrFail($this->option('run')));
        }
        $started = hrtime(true);
        for ($i = 0; $i < max(1, min(100, (int) $this->option('steps'))); $i++) {
            if (! $service->processNext($this->option('run')) || (hrtime(true) - $started) / 1e9 >= 15) {
                break;
            }
        }
        if ($this->option('run') && ($run = DailyPhotoRecoveryRun::findOrFail($this->option('run')))->status === 'FAILED') {
            $this->error($run->error_code.' '.json_encode($run->error_context, JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
