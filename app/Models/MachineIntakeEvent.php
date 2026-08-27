<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineIntakeEvent extends Model
{
    protected $guarded = [];
    protected $casts = ['properties' => 'array', 'occurred_at' => 'datetime'];
    public function intakeCase(): BelongsTo { return $this->belongsTo(MachineIntakeCase::class, 'machine_intake_case_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
