<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPhotoInterval extends Model
{
    public const STATUS_PAIRED = 'PAIRED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_start_at' => 'datetime',
            'raw_end_at' => 'datetime',
        ];
    }

    public function dailyPhotoCase(): BelongsTo
    {
        return $this->belongsTo(DailyPhotoCase::class);
    }

    public function startEvidence(): BelongsTo
    {
        return $this->belongsTo(DailyPhotoCaseEvidence::class, 'start_evidence_id');
    }

    public function endEvidence(): BelongsTo
    {
        return $this->belongsTo(DailyPhotoCaseEvidence::class, 'end_evidence_id');
    }
}
