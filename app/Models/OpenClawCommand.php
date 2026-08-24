<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenClawCommand extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reconciliationJob(): BelongsTo
    {
        return $this->belongsTo(AiReconciliationJob::class, 'ai_reconciliation_job_id');
    }
}
