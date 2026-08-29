<?php

use App\Models\MachineIntakeCase;
use App\Services\MachineIntakeOcrService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

Artisan::command('machine-intakes:enqueue-ocr {reference?} {--retry}', function (?string $reference = null) {
    $query=MachineIntakeCase::query()->with('documents')->when($reference,fn($q)=>$q->where('reference',$reference));
    $count=0; foreach($query->cursor() as $case) $count+=app(MachineIntakeOcrService::class)->enqueueCase($case,(bool)$this->option('retry'));
    $this->info("Queued {$count} machine intake document(s).");
})->purpose('Queue machine intake source documents for structured OCR');

Schedule::command('notifications:sync-operational')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('reconciliation:dispatch-alerts urgent')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('reconciliation:dispatch-alerts warnings')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('reconciliation:dispatch-alerts daily')
    ->dailyAt('07:00')
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping();

Schedule::command('reconciliation:sync-monthly --create-only')
    ->monthlyOn(1, '00:05')
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping();

Schedule::command('reconciliation:sync-monthly')
    ->dailyAt('00:15')
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

Schedule::command('machine-handovers:dispatch-reminders')
    ->hourly()
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
