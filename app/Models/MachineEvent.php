<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineEvent extends Model
{
    protected $guarded = [];

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
