<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ExportReconciliationPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $period = $this->route('reconciliationPeriod');

        return $period instanceof ReconciliationPeriod
            && Gate::allows('export', $period);
    }

    public function rules(): array
    {
        return [
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'command_center_id' => ['nullable', 'integer', 'exists:command_centers,id'],
            'acknowledge_warnings' => ['nullable', 'boolean'],
            'mode' => ['nullable', 'in:workbook,zip'],
        ];
    }
}
