<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

class MachineEvent extends Model
{
    protected $guarded = [];
    protected $casts = ['occurred_at' => 'datetime'];

    protected function eventDate(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'from_project_id');
    }

    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    public function fromCommandCenter(): BelongsTo
    {
        return $this->belongsTo(CommandCenter::class, 'from_command_center_id');
    }

    public function toCommandCenter(): BelongsTo
    {
        return $this->belongsTo(CommandCenter::class, 'to_command_center_id');
    }
}
