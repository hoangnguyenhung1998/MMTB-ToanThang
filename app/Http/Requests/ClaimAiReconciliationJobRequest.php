<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClaimAiReconciliationJobRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
        ];
    }
}
