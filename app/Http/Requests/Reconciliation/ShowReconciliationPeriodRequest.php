<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ShowReconciliationPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $period = $this->route('reconciliationPeriod');

        return $period instanceof ReconciliationPeriod
            && Gate::allows('view', $period);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'command_center_id' => ['nullable', 'integer', 'exists:command_centers,id'],
            'work_date' => ['nullable', 'date'],
            'row_status' => ['nullable', 'in:DRAFT,REVIEWED,CONFIRMED,REJECTED'],
            'change_type' => ['nullable', 'string', 'max:255'],
            'machine_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
