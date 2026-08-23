<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteAiReconciliationJobRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'submission_uuid' => ['required', 'uuid'],
            'outcome' => ['required', Rule::in(['MATCHED', 'WARNING', 'EXCEPTION', 'UNRESOLVED'])],
            'summary' => ['nullable', 'string', 'max:5000'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'agent_name' => ['required', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:150'],
            'metadata' => ['nullable', 'array'],
            'findings' => ['present', 'array', 'max:100'],
            'findings.*.code' => ['required', 'string', 'max:100'],
            'findings.*.severity' => ['required', Rule::in(['INFO', 'WARNING', 'CRITICAL'])],
            'findings.*.title' => ['required', 'string', 'max:255'],
            'findings.*.description' => ['nullable', 'string', 'max:5000'],
            'findings.*.evidence' => ['nullable', 'array'],
            'findings.*.suggested_action' => ['nullable', 'string', 'max:5000'],
            'findings.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
        ];
    }
}
