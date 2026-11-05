<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierObject extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'bytes' => 'integer'];
}
