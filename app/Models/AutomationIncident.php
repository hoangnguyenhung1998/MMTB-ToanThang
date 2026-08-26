<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationIncident extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(AutomationService::class, 'automation_service_id');
    }
}
