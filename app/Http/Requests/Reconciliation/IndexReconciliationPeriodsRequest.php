<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class IndexReconciliationPeriodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', ReconciliationPeriod::class);
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:DRAFT,GENERATED,REVIEWING,CONFIRMED,EXPORTED'],
            'type' => ['nullable', 'in:WEEKLY,MONTHLY'],
        ];
    }
}
