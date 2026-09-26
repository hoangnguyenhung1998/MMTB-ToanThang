<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOcrReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['approve', 'correct', 'correct_and_add_case', 'reject'])], 'machine_id' => ['nullable', Rule::requiredIf(fn (): bool => $this->input('action') === 'correct' || ($this->input('action') === 'correct_and_add_case' && $this->input('expected_disposition', 'DAILY_TIMEMARK') === 'DAILY_TIMEMARK')), 'exists:machines,id'], 'extracted_date' => ['nullable', 'date'], 'extracted_time' => ['nullable', 'date_format:H:i'], 'operator_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'work_location' => ['nullable', 'string'], 'review_notes' => ['nullable', 'string', 'max:2000'], 'case_category' => ['nullable', Rule::in(\App\Models\OcrRegressionCase::CATEGORIES)], 'expected_disposition' => ['nullable', Rule::in(['DAILY_TIMEMARK', 'IGNORED_HOUR_METER', 'IGNORED_NON_DAILY_PHOTO'])]];
    }
}
