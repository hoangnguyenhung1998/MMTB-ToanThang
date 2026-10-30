<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierAiRun extends Model
{
    protected $guarded = [];

    protected $casts = ['context' => 'array', 'group_result' => 'array', 'group_usage' => 'array', 'paused' => 'boolean'];

    public function source()
    {
        return $this->belongsTo(DossierSource::class);
    }

    public function pages()
    {
        return $this->hasMany(DossierAiPage::class, 'run_id');
    }

    public function proposals()
    {
        return $this->hasMany(DossierAiProposal::class, 'run_id');
    }
}
