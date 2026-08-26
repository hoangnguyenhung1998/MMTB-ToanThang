<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
