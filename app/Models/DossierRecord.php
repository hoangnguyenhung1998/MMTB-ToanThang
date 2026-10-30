<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DossierRecord extends Model
{
    protected $guarded = [];

    public function type()
    {
        return $this->belongsTo(DossierType::class, 'type_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function assignment()
    {
        return $this->belongsTo(MachineAssignment::class, 'assignment_id');
    }

    public function versions()
    {
        return $this->hasMany(DossierVersion::class, 'record_id');
    }
}
