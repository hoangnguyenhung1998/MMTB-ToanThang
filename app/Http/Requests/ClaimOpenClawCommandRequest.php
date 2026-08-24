<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClaimOpenClawCommandRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,10'],
        ];
    }
}
