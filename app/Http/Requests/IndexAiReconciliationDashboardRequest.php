<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAiReconciliationDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in(['PENDING', 'PROCESSING', 'RETRY', 'WAITING_EVIDENCE', 'COMPLETED', 'FAILED'])],
            'outcome' => ['nullable', Rule::in(['MATCHED', 'WARNING', 'EXCEPTION', 'UNRESOLVED'])],
        ];
    }
}
