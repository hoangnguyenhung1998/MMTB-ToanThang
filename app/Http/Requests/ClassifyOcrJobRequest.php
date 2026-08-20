<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'document_type' => ['required', 'string', 'in:DAILY_TIMEMARK,WEEKLY_JOURNAL'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
        ];
    }
}
