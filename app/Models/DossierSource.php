<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierSource extends Model
{
    protected $guarded = [];

    protected $casts = ['previews' => 'array', 'candidates' => 'array', 'excluded_pages' => 'array', 'processing' => 'array', 'confirmed' => 'boolean', 'machine_confirmed' => 'boolean'];

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function batch()
    {
        return $this->belongsTo(DossierBatch::class, 'batch_id');
    }
}
