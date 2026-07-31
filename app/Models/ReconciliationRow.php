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
        'ocr_regular_hours' => 'decimal:2',
        'ocr_overtime_afternoon' => 'decimal:2',
        'ocr_overtime_evening' => 'decimal:2',
        'confirmed_regular_hours' => 'decimal:2',
        'confirmed_overtime_afternoon' => 'decimal:2',
        'confirmed_overtime_evening' => 'decimal:2',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReconciliationPeriod::class, 'reconciliation_period_id');
    }

    public function dailyAssignment(): BelongsTo
    {
        return $this->belongsTo(MachineDailyAssignment::class, 'machine_daily_assignment_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
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
}
