<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierExport extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['manifest' => 'array', 'warnings' => 'array'];

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }
}
