<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierVersion extends Model
{
    protected $guarded = [];

    protected $casts = ['pages' => 'array', 'confirmed' => 'boolean', 'archived' => 'boolean', 'shared_pages' => 'boolean', 'provenance' => 'array'];

    public function record()
    {
        return $this->belongsTo(DossierRecord::class, 'record_id');
    }

    public function source()
    {
        return $this->belongsTo(DossierSource::class, 'source_id');
    }
}
