<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyPhotoRecoveryRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lease_until' => 'datetime', 'error_context' => 'array', 'filters' => 'array', 'counters' => 'array', 'discovery_done' => 'boolean', 'finished_at' => 'datetime'];
    }
}
