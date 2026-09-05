<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmMachineIntakeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company' => ['required', new \App\Rules\AvailableCompany($this->route('machineIntake') instanceof \App\Models\MachineIntakeCase ? $this->route('machineIntake')->company : null)], 'chassis_no' => ['required', 'string', 'max:255'],
            'engine_no' => ['required', 'string', 'max:255'], 'plate_no' => ['nullable', 'string', 'max:255'],
            'machine_type' => ['required', 'string', 'max:255'], 'model_name' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable','string','max:255'], 'plate_no' => ['nullable','string','max:255'],
            'capacity_class' => ['nullable','integer','in:55,140,200,300'], 'vehicle_axles' => ['nullable','integer','in:2,3,4'],
            'project_id' => ['nullable','integer','exists:projects,id'], 'handover_at' => ['nullable','date'],
            'manufacture_year' => ['required', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
        ];
    }
}
