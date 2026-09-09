<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyPhotoCase extends Model
{
    public const STATUS_COLLECTING = 'COLLECTING';

    public const STATUS_READY = 'READY';

    public const STATUS_PAIRING_AMBIGUOUS = 'PAIRING_AMBIGUOUS';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'source_metadata' => 'array',
            'pairing_diagnostics' => 'array',
            'pairing_computed_at' => 'datetime',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function machineAssignment(): BelongsTo
    {
        return $this->belongsTo(MachineAssignment::class);
    }

    public function ocrJobs(): HasMany
    {
        return $this->hasMany(OcrJob::class);
    }

    public function evidenceMemberships(): HasMany
    {
        return $this->hasMany(DailyPhotoCaseEvidence::class);
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(DailyPhotoInterval::class)->orderBy('sequence');
    }
}
