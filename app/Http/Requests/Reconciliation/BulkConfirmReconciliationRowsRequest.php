<?php

namespace App\Http\Requests\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;

class BulkConfirmReconciliationRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviewMonthly', $this->route('reconciliationPeriod')) ?? false;
    }

    public function rules(): array
    {
        return [
            'machine_id' => ['required', 'integer'],
            'row_ids' => ['required', 'array'],
            'row_ids.*' => ['integer'],
        ];
    }
}
