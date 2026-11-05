<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierType extends Model
{
    protected $guarded = [];

    protected $casts = ['groups' => 'array', 'required_groups' => 'array', 'warning_days' => 'array'];
}
