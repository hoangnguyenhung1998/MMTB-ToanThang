<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierAiProposal extends Model
{
    protected $guarded = [];

    protected $casts = ['data' => 'array', 'issues' => 'array', 'reviewed' => 'array'];

    public function run()
    {
        return $this->belongsTo(DossierAiRun::class, 'run_id');
    }
}
