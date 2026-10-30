<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierAiPage extends Model
{
    protected $guarded = [];

    public function run()
    {
        return $this->belongsTo(DossierAiRun::class, 'run_id');
    }

    protected $casts = ['result' => 'array', 'raw' => 'array', 'usage' => 'array'];
}
