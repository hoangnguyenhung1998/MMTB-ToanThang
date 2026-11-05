<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierSenderProfile extends Model
{
    protected $guarded = [];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
