<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMachineIntakeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source_channel' => ['nullable', 'in:WEB,ZALO,TELEGRAM,EMAIL'],
            'company' => ['nullable', 'in:VINCONS,VINALPHA'],
            'chassis_no' => ['nullable', 'string', 'max:255'], 'engine_no' => ['nullable', 'string', 'max:255'],
            'plate_no' => ['nullable', 'string', 'max:255'], 'machine_type' => ['nullable', 'string', 'max:255'],
            'model_name' => ['nullable', 'string', 'max:255'],
            'manufacture_year' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'document_type' => ['nullable', 'in:MACHINE_PHOTO,REGISTRATION_CERTIFICATE,CHASSIS_PLATE,ENGINE_PLATE,OTHER'],
            'documents' => ['required', 'array', 'min:1'], 'documents.*' => ['file', 'mimes:jpg,jpeg,png,webp,bmp,tif,tiff', 'max:204800'],
        ];
    }
}
