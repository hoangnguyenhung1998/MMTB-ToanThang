<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomationOperationalCommandRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['RESTART', 'PAUSE', 'RETRY', 'HEALTH_CHECK', 'ZALO_ACCOUNT_SWITCH'])],
            'account_id' => ['nullable', 'required_if:action,ZALO_ACCOUNT_SWITCH', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]{0,49}$/'],
        ];
    }
}
