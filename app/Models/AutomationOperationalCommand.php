<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationOperationalCommand extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => 'array', 'claimed_at' => 'datetime', 'lease_expires_at' => 'datetime',
            'completed_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }

    public function node(): BelongsTo { return $this->belongsTo(AutomationNode::class, 'automation_node_id'); }
    public function service(): BelongsTo { return $this->belongsTo(AutomationService::class, 'automation_service_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
