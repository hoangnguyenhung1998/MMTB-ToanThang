<?php

namespace App\Providers;

use App\Models\CommandCenter;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineDocument;
use App\Models\MachineEvent;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Observers\ActivityObserver;
use App\Policies\ReconciliationPeriodPolicy;
use App\Policies\ReconciliationRowPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('collector-api', fn (Request $request) => Limit::perMinute(
            (int) config('collector.rate_limit_per_minute', 240)
        )->by('collector:'.hash('sha256', (string) $request->bearerToken())));

        RateLimiter::for('ocr-worker-api', fn (Request $request) => Limit::perMinute(
            (int) config('ocr.rate_limit_per_minute', 240)
        )->by('ocr:'.hash('sha256', (string) $request->bearerToken())));

        RateLimiter::for('openclaw-api', fn (Request $request) => Limit::perMinute(
            (int) config('openclaw.rate_limit_per_minute', 120)
        )->by('openclaw:'.hash('sha256', (string) $request->bearerToken())));

        Gate::policy(ReconciliationPeriod::class, ReconciliationPeriodPolicy::class);
        Gate::policy(ReconciliationRow::class, ReconciliationRowPolicy::class);

        Machine::observe(ActivityObserver::class);
        MachineAssignment::observe(ActivityObserver::class);
        MachineEvent::observe(ActivityObserver::class);
        MachineDocument::observe(ActivityObserver::class);
        Driver::observe(ActivityObserver::class);
        DriverDocument::observe(ActivityObserver::class);
        Project::observe(ActivityObserver::class);
        CommandCenter::observe(ActivityObserver::class);
    }
}
