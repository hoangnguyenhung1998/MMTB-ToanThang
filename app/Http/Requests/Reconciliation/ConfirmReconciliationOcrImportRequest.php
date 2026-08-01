<?php

namespace App\Http\Requests\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmReconciliationOcrImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('importOcr', $this->route('reconciliationPeriod')) ?? false;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }
}
