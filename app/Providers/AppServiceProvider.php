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
use App\Observers\ActivityObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
