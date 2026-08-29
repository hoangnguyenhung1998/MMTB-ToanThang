<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineHandoverOcrJob extends Model
{
    protected $guarded = [];
    protected $casts = [
        'claimed_at' => 'datetime', 'lease_expires_at' => 'datetime', 'processed_at' => 'datetime',
        'confidence' => 'float', 'extraction' => 'array', 'review_flags' => 'array',
    ];
    public function document(): BelongsTo { return $this->belongsTo(MachineHandoverDocument::class, 'machine_handover_document_id'); }
}
