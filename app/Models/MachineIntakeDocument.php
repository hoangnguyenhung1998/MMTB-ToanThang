<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MachineIntakeDocument extends Model
{
    protected $guarded = [];
    protected $casts = ['extraction_json' => 'array', 'confidence' => 'float'];
    public function intakeCase(): BelongsTo { return $this->belongsTo(MachineIntakeCase::class, 'machine_intake_case_id'); }
    public function ocrJob(): HasOne { return $this->hasOne(MachineIntakeOcrJob::class); }
}
