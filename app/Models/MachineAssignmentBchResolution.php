<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineAssignmentBchResolution extends Model
{
    protected $guarded = [];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MachineAssignment::class, 'machine_assignment_id');
    }

    public function commandCenter(): BelongsTo
    {
        return $this->belongsTo(CommandCenter::class);
    }
}
