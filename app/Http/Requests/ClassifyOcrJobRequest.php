<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassifyOcrJobRequest extends FormRequest
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
            'document_type' => ['required', 'string', 'in:UNKNOWN,DAILY_TIMEMARK,WEEKLY_JOURNAL'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
        ];
    }
}
