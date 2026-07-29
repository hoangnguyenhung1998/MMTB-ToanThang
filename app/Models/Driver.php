<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function machineHistories(): HasMany
    {
        return $this->hasMany(MachineDriverHistory::class);
    }

    public function currentMachines(): HasMany
    {
        return $this->hasMany(Machine::class, 'current_driver_id');
    }
}
