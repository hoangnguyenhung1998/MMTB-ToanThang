<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\ReconciliationRow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ConfirmReconciliationRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $row = $this->route('reconciliationRow');

        return $row instanceof ReconciliationRow
            && Gate::allows('confirm', $row);
    }

    public function rules(): array
    {
        return [];
    }
}
