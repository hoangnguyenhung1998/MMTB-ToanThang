<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZaloSenderMachineMapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_to' => 'datetime'];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
