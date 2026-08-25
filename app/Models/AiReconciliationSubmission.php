<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiReconciliationSubmission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'metadata' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AiReconciliationJob::class, 'ai_reconciliation_job_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AiReconciliationFinding::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(AiReconciliationAlert::class);
    }
}
