<?php

namespace App\Http\Requests;

use App\Models\MachineIntakeCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignMachineIntakeCodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'asset_code' => ['required', 'string', 'max:255'],
            'asset_code_source' => ['required', Rule::in(MachineIntakeCase::CODE_SOURCES)],
            'asset_code_source_note' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'file', 'max:204800'],
        ];
    }
}
