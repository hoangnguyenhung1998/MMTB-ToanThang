<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexOcrReviewsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['PENDING', 'PROCESSING', 'RETRY', 'COMPLETED', 'EXCEPTION', 'FAILED'])],
            'document_type' => ['nullable', Rule::in(['UNKNOWN', 'DAILY_TIMEMARK', 'WEEKLY_JOURNAL'])],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
