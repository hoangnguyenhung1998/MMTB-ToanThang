<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalDocument extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'exceptions' => 'array',
        ];
    }

    public function ocrJob(): BelongsTo
    {
        return $this->belongsTo(OcrJob::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(JournalRow::class)->orderBy('row_number');
    }
}
