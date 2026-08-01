<?php

namespace App\Http\Requests\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;

class UploadReconciliationOcrImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('importOcr', $this->route('reconciliationPeriod')) ?? false;
    }

    public function rules(): array
    {
        return [
            'ocr_file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv'],
        ];
    }

    public function attributes(): array
    {
        return [
            'ocr_file' => 'file OCR',
        ];
    }
}
