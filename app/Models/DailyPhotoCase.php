<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyPhotoCase extends Model
{
    public const STATUS_COLLECTING = 'COLLECTING';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'source_metadata' => 'array',
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
}
