<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWeeklyJournalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['save', 'approve', 'reject'])],
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required_unless:action,reject', 'array', 'max:100'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.delete' => ['nullable', 'boolean'],
            'rows.*.work_date' => ['nullable', 'date'],
            'rows.*.start_time' => ['nullable', 'date_format:H:i'],
            'rows.*.end_time' => ['nullable', 'date_format:H:i'],
            'rows.*.work_content' => ['nullable', 'string', 'max:5000'],
            'rows.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'rows.*.unit' => ['nullable', 'string', 'max:50'],
            'rows.*.work_location' => ['nullable', 'string', 'max:2000'],
            'rows.*.operator_name' => ['nullable', 'string', 'max:255'],
            'rows.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
        ];
    }
}
