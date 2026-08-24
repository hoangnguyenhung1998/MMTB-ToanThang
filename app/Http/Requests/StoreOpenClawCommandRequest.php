<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpenClawCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in([
                'RECONCILE_AGAIN',
                'DEEP_ANALYSIS',
                'EXPLAIN_RESULT',
                'CHECK_EVIDENCE',
                'REVIEW_FINDINGS',
                'SUMMARIZE',
                'GENERAL_ANALYSIS',
            ])],
            'instruction' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }
}
