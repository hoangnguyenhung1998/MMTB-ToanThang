<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmMachineIntakeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company' => ['required', 'in:VINCONS,VINALPHA'], 'chassis_no' => ['required', 'string', 'max:255'],
            'engine_no' => ['required', 'string', 'max:255'], 'plate_no' => ['nullable', 'string', 'max:255'],
            'machine_type' => ['required', 'string', 'max:255'], 'model_name' => ['nullable', 'string', 'max:255'],
            'manufacture_year' => ['required', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
        ];
    }
}
