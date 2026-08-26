<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationHealthAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(AutomationIncident::class, 'automation_incident_id');
    }
}
