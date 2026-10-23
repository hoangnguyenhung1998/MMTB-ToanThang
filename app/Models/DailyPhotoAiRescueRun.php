<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyPhotoAiRescueRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['job_ids' => 'array', 'reason_groups' => 'array', 'enqueue_done' => 'boolean', 'lease_until' => 'datetime'];
    }
}
