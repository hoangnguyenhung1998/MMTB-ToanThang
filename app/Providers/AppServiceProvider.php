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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
