<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenewOcrJobLeaseRequest extends FormRequest
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
        ];
    }
}
