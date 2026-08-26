<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationService extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'last_success_at' => 'datetime',
            'metrics' => 'array',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(AutomationNode::class, 'automation_node_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(AutomationIncident::class);
    }
}
