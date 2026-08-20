<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'quantity' => 'decimal:2',
            'confidence' => 'decimal:4',
            'raw_data' => 'array',
            'exceptions' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(JournalDocument::class, 'journal_document_id');
    }
}
