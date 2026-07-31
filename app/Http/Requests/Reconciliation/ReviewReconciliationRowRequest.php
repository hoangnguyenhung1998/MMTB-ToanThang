<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\ReconciliationRow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReviewReconciliationRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $row = $this->route('reconciliationRow');

        return $row instanceof ReconciliationRow
            && Gate::allows('review', $row);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:accept,reject'],
            'comment' => ['required_if:decision,reject', 'nullable', 'string', 'max:5000'],
        ];
    }
}
