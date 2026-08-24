<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteJournalOcrJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'asset_code' => ['nullable', 'string', 'max:100'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'raw_text' => ['nullable', 'string'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.row_number' => ['required', 'integer', 'min:1', 'distinct'],
            'rows.*.work_date' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.start_time' => ['nullable', 'date_format:H:i:s'],
            'rows.*.end_time' => ['nullable', 'date_format:H:i:s'],
            'rows.*.total_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'rows.*.work_content' => ['nullable', 'string', 'max:5000'],
            'rows.*.error_explanation' => ['nullable', 'string', 'max:5000'],
            'rows.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'rows.*.unit' => ['nullable', 'string', 'max:50'],
            'rows.*.work_location' => ['nullable', 'string', 'max:2000'],
            'rows.*.operator_name' => ['nullable', 'string', 'max:255'],
            'rows.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'rows.*.raw_data' => ['nullable', 'array'],
        ];
    }
}
