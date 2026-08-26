<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FailAutomationOperationalCommandRequest extends FormRequest
{
    public function rules(): array { return ['error' => ['required', 'string', 'max:2000']]; }
}
