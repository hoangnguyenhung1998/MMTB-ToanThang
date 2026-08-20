<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ZaloAttachment extends Model
{
    protected $guarded = [];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ZaloMessage::class, 'zalo_message_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_attachment_id');
    }

    public function ocrJob(): HasOne
    {
        return $this->hasOne(OcrJob::class);
    }
}
