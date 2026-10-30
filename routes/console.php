<?php

use App\Models\MachineIntakeCase;
use App\Services\MachineIntakeOcrService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Compatibility drains for pre-release Laravel queue records. New requests write
// remote jobs atomically; laptop polls without a hosting PDF/AI queue worker.
Artisan::command('dossiers:work {--max-time=50} {--watch}', function () {
    config(['queue.failed.table' => 'dossier_failed_jobs']);
    $this->call('queue:work', ['connection' => 'dossiers', '--queue' => 'dossiers,dossiers-ai', '--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3, '--timeout' => 60]);
})->purpose('Drain legacy dossier entries into remote job queue; no native processing');

Artisan::command('dossiers:ai-work {--watch}', function () {
    if (! config('dossiers.ai.enabled')) {
        $this->info('AI hồ sơ đang tắt; không xếp hoặc gọi API.');

        return;
    }
    \App\Models\DossierAiRun::whereIn('status', ['queued', 'unconfigured', 'processing'])->where('paused', false)->orderBy('id')->chunkById(20, function ($runs) {
        foreach ($runs as $run) {
            app(\App\Services\Dossiers\Ai\DossierAiService::class)->schedule($run);
        }
    });
    $this->info('Đã kiểm tra hàng đợi remote; AI chỉ chạy tại worker hồ sơ.');
})->purpose('Reconcile remote AI scheduling only; no Python on hosting');

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
