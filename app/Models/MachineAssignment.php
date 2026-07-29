<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineAssignment extends Model
{
    protected $guarded = [];
    protected $casts = [
        'time_in' => 'datetime',
        'time_out' => 'datetime',
    ];

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
