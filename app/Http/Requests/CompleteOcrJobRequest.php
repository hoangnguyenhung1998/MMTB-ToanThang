<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteOcrJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'attempt' => [Rule::requiredIf((bool) config('ocr.enforce_attempt_fencing')), 'nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i:s'],
            'asset_code' => ['nullable', 'string', 'max:100'],
            'operator_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'work_location' => ['nullable', 'string', 'max:2000'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'raw_text' => ['nullable', 'string'],
            'image_fingerprint' => ['nullable', 'regex:/^[a-f0-9]{16}$/'],
        ];
    }
}
