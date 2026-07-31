<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MachineDailyAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'work_date' => 'date',
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

    public function reconciliationRow(): HasOne
    {
        return $this->hasOne(ReconciliationRow::class);
    }
}
