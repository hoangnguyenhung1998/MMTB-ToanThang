<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReconciliationRepairRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['periods' => 'array'];
}
