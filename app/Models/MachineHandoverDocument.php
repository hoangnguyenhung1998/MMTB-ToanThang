<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MachineHandoverDocument extends Model
{
    protected $guarded = [];
    protected $casts = ['extraction_json' => 'array', 'confidence' => 'float'];
    public function handoverCase(): BelongsTo { return $this->belongsTo(MachineHandoverCase::class, 'machine_handover_case_id'); }
    public function ocrJob(): HasOne { return $this->hasOne(MachineHandoverOcrJob::class); }
}
