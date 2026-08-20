<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OcrJob extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'classification_confidence' => 'decimal:4',
            'classified_at' => 'datetime',
            'extracted_date' => 'date:Y-m-d',
            'confidence' => 'decimal:4',
            'exceptions' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(ZaloAttachment::class, 'zalo_attachment_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function journalDocument(): HasOne
    {
        return $this->hasOne(JournalDocument::class);
    }
}
