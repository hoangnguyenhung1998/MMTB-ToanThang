<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPhotoAiRescueAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'extracted_date' => 'date:Y-m-d',
            'ambiguities' => 'array',
            'structured_response' => 'array',
            'validation_outcome' => 'array',
            'usage' => 'array',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function ocrJob(): BelongsTo
    {
        return $this->belongsTo(OcrJob::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(ZaloAttachment::class, 'zalo_attachment_id');
    }
}
