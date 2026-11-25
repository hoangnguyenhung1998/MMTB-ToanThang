<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierType extends Model
{
    protected $guarded = [];

    protected $casts = ['groups' => 'array', 'required_groups' => 'array', 'warning_days' => 'array'];

    public function supportsGroup(?string $group): bool
    {
        return in_array($group, $this->groups, true)
            || ($group === 'machine' && in_array($this->ai_key, ['registration', 'vehicle_inspection'], true)
                && in_array('vehicle', $this->groups, true));
    }
}
