<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteOpenClawCommandRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'summary' => ['required', 'string', 'max:10000'],
            'result' => ['nullable', 'array'],
        ];
    }
}
