<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationRow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'work_date' => 'date',
        'reviewed_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReconciliationPeriod::class, 'reconciliation_period_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MachineAssignment::class, 'machine_assignment_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function commandCenter(): BelongsTo
    {
        return $this->belongsTo(CommandCenter::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
