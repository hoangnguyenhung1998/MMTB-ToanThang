<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierSource extends Model
{
    protected $guarded = [];

    protected $casts = ['previews' => 'array', 'candidates' => 'array', 'excluded_pages' => 'array', 'processing' => 'array', 'document_target' => 'array', 'confirmed' => 'boolean', 'machine_confirmed' => 'boolean'];

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function replacementVersion()
    {
        return $this->belongsTo(DossierVersion::class);
    }

    public function latestAiRun()
    {
        return $this->hasOne(DossierAiRun::class, 'source_id')->latestOfMany();
    }

    public function versions()
    {
        return $this->hasMany(DossierVersion::class, 'source_id');
    }

    public function batch()
    {
        return $this->belongsTo(DossierBatch::class, 'batch_id');
    }
}
