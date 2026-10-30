<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierAiPage extends Model
{
    protected $guarded = [];

    protected $casts = ['result' => 'array', 'raw' => 'array', 'usage' => 'array'];
}
