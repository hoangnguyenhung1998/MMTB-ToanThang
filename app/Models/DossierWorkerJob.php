<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierWorkerJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['input' => 'array', 'result' => 'array', 'attempts' => 'integer', 'lease_until' => 'immutable_datetime', 'claimed_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime'];
}
