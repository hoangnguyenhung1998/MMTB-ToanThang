<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiReconciliationJob extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AiReconciliationSubmission::class);
    }

    public function latestSubmission(): HasOne
    {
        return $this->hasOne(AiReconciliationSubmission::class)->latestOfMany('submitted_at');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(OpenClawCommand::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(AiReconciliationAlert::class);
    }
}
