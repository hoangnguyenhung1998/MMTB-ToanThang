<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MachineHandoverCase extends Model
{
    protected $guarded = [];
    protected $casts = [
        'handover_date' => 'date', 'extraction' => 'array', 'review_flags' => 'array',
        'confidence' => 'float', 'confirmed_at' => 'datetime', 'missing_data_alerted_at' => 'datetime',
        'ready_alerted_at' => 'datetime', 'reminder_alerted_at' => 'datetime',
    ];

    public function machine(): BelongsTo { return $this->belongsTo(Machine::class); }
    public function intakeCase(): BelongsTo { return $this->belongsTo(MachineIntakeCase::class, 'machine_intake_case_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function commandCenter(): BelongsTo { return $this->belongsTo(CommandCenter::class); }
    public function documents(): HasMany { return $this->hasMany(MachineHandoverDocument::class); }
}
