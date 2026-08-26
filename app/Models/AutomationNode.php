<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationNode extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_heartbeat_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(AutomationService::class);
    }
}
