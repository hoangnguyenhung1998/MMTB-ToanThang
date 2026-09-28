<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FailDailyPhotoAiRescueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'attempt' => ['required', 'integer', 'min:1'],
            'provider' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:150'],
            'error' => ['required', 'string', 'max:5000'],
            'retryable' => ['required', 'boolean'],
        ];
    }
}
