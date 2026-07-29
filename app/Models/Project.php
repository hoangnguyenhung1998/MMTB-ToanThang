<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $guarded = [];

    public function assignments(): HasMany
    {
        return $this->hasMany(MachineAssignment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MachineEvent::class);
    }
}
