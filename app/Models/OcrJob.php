<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
            'review_flags' => 'array',
            'reviewed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $job): void {
            if (! $job->wasChanged('status') || $job->reviewed_at || ! in_array($job->status, ['COMPLETED','EXCEPTION','FAILED'], true)) return;
            $sample = max(0, min(100, (int) config('ocr.review_sample_percent', 3)));
            $sampled = (abs(crc32((string) $job->id)) % 100) < $sample;
            $job->newQuery()->whereKey($job->id)->update([
                'review_status' => $job->status === 'COMPLETED' && ! $sampled ? 'AUTO_APPROVED' : 'PENDING',
                'review_flags' => $sampled ? json_encode(['QUALITY_SAMPLE']) : null,
            ]);
        });
    }

    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function activities(): MorphMany { return $this->morphMany(ActivityLog::class, 'subject')->latest('occurred_at'); }

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
