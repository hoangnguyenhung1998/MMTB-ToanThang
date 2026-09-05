<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(fn (self $model) => app(\App\Services\CatalogIntegrityService::class)->assertUnused($model));
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MachineAssignment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MachineEvent::class);
    }
}
