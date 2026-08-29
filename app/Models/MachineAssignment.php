<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

class MachineAssignment extends Model
{
    protected $guarded = [];
    protected $casts = [
        'time_in' => 'datetime',
        'time_out' => 'datetime',
    ];

    protected function handoverDate(): Attribute
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
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function commandCenter(): BelongsTo
    {
        return $this->belongsTo(CommandCenter::class, 'command_center_id');
    }
}
