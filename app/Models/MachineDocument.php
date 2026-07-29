<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineDocument extends Model
{
    protected $guarded = [];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
