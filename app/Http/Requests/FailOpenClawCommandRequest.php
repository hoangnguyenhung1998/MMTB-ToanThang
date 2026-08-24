<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FailOpenClawCommandRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'error' => ['required', 'string', 'max:5000'],
            'retryable' => ['required', 'boolean'],
        ];
    }
}
