<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    protected $guarded = [];

    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'current_driver_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MachineAssignment::class, 'machine_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MachineEvent::class);
    }

    public function driverHistories(): HasMany
    {
        return $this->hasMany(MachineDriverHistory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MachineDocument::class);
    }

    public function aiReconciliationJobs(): HasMany
    {
        return $this->hasMany(AiReconciliationJob::class);
    }

    public function intakeCase()
    {
        return $this->hasOne(MachineIntakeCase::class);
    }

    public function handoverCases(): HasMany
    {
        return $this->hasMany(MachineHandoverCase::class);
    }

    public function currentAssignment()
    {
        return $this->hasOne(MachineAssignment::class, 'machine_id')
            ->whereNull('time_out')
            ->latestOfMany('time_in');
    }
    public function latestAssignment()
    {
        return $this->hasOne(MachineAssignment::class, 'machine_id')
            ->latestOfMany('time_in');
    }
    // public function getStatusLabelAttribute()
    // {
    //     if ($this->currentAssignment) {
    //         return 'ACTIVE';
    //     }

    //     if ($this->latestAssignment && $this->latestAssignment->time_out) {
    //         return 'RETURNED';
    //     }

    //     return 'WAIT_HANDOVER';
    // }
}
