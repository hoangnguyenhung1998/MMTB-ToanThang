<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineIntakeEmailReply extends Model
{
    protected $guarded = [];
    protected $casts = ['received_at' => 'datetime', 'confirmed_at' => 'datetime', 'confidence' => 'float', 'metadata' => 'array'];

    public function intakeCase(): BelongsTo { return $this->belongsTo(MachineIntakeCase::class, 'machine_intake_case_id'); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by'); }
}
