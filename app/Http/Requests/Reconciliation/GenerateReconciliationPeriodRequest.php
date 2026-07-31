<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class GenerateReconciliationPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $period = $this->route('reconciliationPeriod');

        return $period instanceof ReconciliationPeriod
            && Gate::allows('generate', $period);
    }

    public function rules(): array
    {
        return [];
    }
}
