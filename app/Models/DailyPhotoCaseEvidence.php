<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DailyPhotoCaseEvidence extends Model
{
    public const STATE_UNMATCHED = 'UNMATCHED';

    public const STATE_PAIRED = 'PAIRED';

    public const STATE_AMBIGUOUS = 'AMBIGUOUS';

    protected $table = 'daily_photo_case_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'capture_datetime' => 'datetime',
        ];
    }

    public function dailyPhotoCase(): BelongsTo
    {
        return $this->belongsTo(DailyPhotoCase::class);
    }

    public function ocrJob(): BelongsTo
    {
        return $this->belongsTo(OcrJob::class);
    }

    public function startInterval(): HasOne
    {
        return $this->hasOne(DailyPhotoInterval::class, 'start_evidence_id');
    }

    public function endInterval(): HasOne
    {
        return $this->hasOne(DailyPhotoInterval::class, 'end_evidence_id');
    }
}
