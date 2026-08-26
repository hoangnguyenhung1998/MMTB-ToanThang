<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexDailyImageArchiveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'command_center_id' => ['nullable', 'integer', 'exists:command_centers,id'],
            'completeness' => ['nullable', 'in:complete,incomplete'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
