<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\ReconciliationPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreReconciliationPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', ReconciliationPeriod::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:MONTHLY'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
