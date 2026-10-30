<?php

use App\Models\MachineIntakeCase;
use App\Services\MachineIntakeOcrService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('dossiers:work {--max-time=50} {--watch : Keep polling only the dossier queue}', function () {
    config(['queue.failed.table' => 'dossier_failed_jobs']);
    $watch = (bool) $this->option('watch');
    $this->call('queue:work', ['connection' => 'dossiers', '--queue' => 'dossiers', '--stop-when-empty' => ! $watch,
        '--max-time' => $watch ? 0 : max(1, min(300, (int) $this->option('max-time'))), '--sleep' => 2, '--tries' => 3, '--timeout' => 900, '--memory' => 256]);
})->purpose('Drain only dossier PDF jobs using isolated queue/failure tables');

Schedule::command('dossiers:work')->everyMinute()->withoutOverlapping(20);

Artisan::command('dossiers:ai-work {--watch}', function () {
    if (! config('dossiers.ai.enabled')) {
        $this->info('AI hồ sơ đang tắt; không xếp hoặc gọi API.');

        return;
    }
    if (! \Illuminate\Support\Facades\Schema::hasTable('dossier_ai_runs')) {
        $this->warn('Chưa có schema Phase18.1; xác minh/backup LOCAL rồi migrate đúng path trước.');

        return;
    }
    config(['queue.failed.table' => 'dossier_failed_jobs']);
    // Resume durable runs after an abandoned job/lease; duplicate jobs are fenced.
    \App\Models\DossierAiRun::whereIn('status', ['queued', 'processing'])->where('paused', false)->orderBy('id')->limit(20)->pluck('id')->each(fn ($id) => \App\Jobs\AnalyzeDossier::dispatch($id));
    $this->call('queue:work', ['connection' => 'dossiers', '--queue' => 'dossiers-ai', '--stop-when-empty' => ! $this->option('watch'),
        '--max-time' => $this->option('watch') ? 0 : 50, '--sleep' => 2, '--tries' => 3, '--timeout' => 360, '--memory' => 256]);
})->purpose('Run only dossier AI; never claim Journal/Collector jobs');
Schedule::command('dossiers:ai-work')->everyMinute()->withoutOverlapping(10);

Artisan::command('dossiers:reminders', function () {
    $this->info('Sent: '.app(\App\Services\Dossiers\DossierReminderService::class)->run());
})->purpose('Send explicitly configured electronic dossier expiry reminders');

Schedule::command('dossiers:reminders')->dailyAt('07:15')->timezone('Asia/Ho_Chi_Minh')->withoutOverlapping();

Schedule::command('ocr:process-daily-recovery-runs --steps=10')->everyMinute()->withoutOverlapping(2);

Schedule::command('reconciliation:process-repair-runs --limit=1')->everyMinute()->withoutOverlapping();

Artisan::command('machine-intakes:enqueue-ocr {reference?} {--retry}', function (?string $reference = null) {
    $query = MachineIntakeCase::query()->with('documents')->when($reference, fn ($q) => $q->where('reference', $reference));
    $count = 0;
    foreach ($query->cursor() as $case) {
        $count += app(MachineIntakeOcrService::class)->enqueueCase($case, (bool) $this->option('retry'));
    }
    $this->info("Queued {$count} machine intake document(s).");
})->purpose('Queue machine intake source documents for structured OCR');

Schedule::command('notifications:sync-operational')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('reconciliation:dispatch-alerts urgent')
    ->when(fn () => ! config('daily_photos.enabled'))
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('reconciliation:dispatch-alerts warnings')
    ->when(fn () => ! config('daily_photos.enabled'))
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('reconciliation:dispatch-alerts daily')
    ->when(fn () => ! config('daily_photos.enabled'))
    ->dailyAt('07:00')
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping();

Schedule::command('reconciliation:sync-monthly --create-only')
    ->monthlyOn(1, '00:05')
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping();

Schedule::command('reconciliation:sync-monthly')
    ->everyFifteenMinutes()
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping();

Schedule::command('reconciliation:sync-evidence')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('automation:evaluate-health')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('automation:dispatch-alerts')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::call(fn () => app(App\Services\OcrCapacityAlertRecorder::class)->evaluate())
    ->name('ocr:monitor-capacity')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::call(fn () => app(App\Services\OcrJobService::class)->expireLeases())
    ->name('ocr:expire-leases')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('machine-handovers:dispatch-reminders')
    ->hourly()
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ocr:process-ai-rescue-runs --steps=50')->everyMinute()->withoutOverlapping(2);
