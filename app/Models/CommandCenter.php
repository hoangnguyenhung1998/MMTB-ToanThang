<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommandCenter extends Model
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

    public function eventsFrom(): HasMany
    {
        return $this->hasMany(MachineEvent::class, 'from_command_center_id');
    }

    public function eventsTo(): HasMany
    {
        return $this->hasMany(MachineEvent::class, 'to_command_center_id');
    }
}
