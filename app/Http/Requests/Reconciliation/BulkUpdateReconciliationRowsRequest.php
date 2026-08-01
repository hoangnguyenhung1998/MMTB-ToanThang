<?php

namespace App\Http\Requests\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateReconciliationRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviewMonthly', $this->route('reconciliationPeriod')) ?? false;
    }

    public function rules(): array
    {
        return [
            'machine_id' => ['required', 'integer'],
            'rows' => ['required', 'array'],
            'rows.*.selected' => ['nullable', 'boolean'],
            'rows.*.location' => ['nullable', 'string', 'max:255'],
            'rows.*.work_content' => ['nullable', 'string', 'max:1000'],
            'rows.*.explanation' => ['nullable', 'string', 'max:1000'],
            'rows.*.review_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
