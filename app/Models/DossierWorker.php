<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierWorker extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['capabilities' => 'array', 'versions' => 'array', 'ai_ready' => 'boolean', 'last_seen_at' => 'immutable_datetime'];
}
