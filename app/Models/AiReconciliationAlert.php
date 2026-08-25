<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReconciliationAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AiReconciliationJob::class, 'ai_reconciliation_job_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AiReconciliationSubmission::class, 'ai_reconciliation_submission_id');
    }
}
