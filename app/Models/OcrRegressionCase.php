<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrRegressionCase extends Model
{
    public const CATEGORIES = [
        'TIME_PARSE', 'DATE_PARSE', 'MACHINE_PARSE', 'SOURCE_PRIORITY',
        'MAPPING_FALLBACK', 'HOUR_METER', 'NON_DAILY_PHOTO', 'DOWNSTREAM', 'OTHER',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_snapshot' => 'array',
            'source_metadata' => 'array',
            'current_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'verified_at' => 'datetime',
        ];
    }

    public function sourceJob(): BelongsTo
    {
        return $this->belongsTo(OcrJob::class, 'source_ocr_job_id');
    }

    public function sourceAttachment(): BelongsTo
    {
        return $this->belongsTo(ZaloAttachment::class, 'source_attachment_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
