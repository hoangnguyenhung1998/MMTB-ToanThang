<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomationOperationalCommandRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['RESTART', 'PAUSE', 'RETRY', 'HEALTH_CHECK'])]];
    }
}
