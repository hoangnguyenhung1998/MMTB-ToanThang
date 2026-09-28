<?php

namespace App\Http\Requests;

use App\Services\DailyPhotoExceptionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexOcrReviewsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'workflow' => ['nullable', Rule::in(['manual', 'canonical', 'protected', 'reviewed'])],
            'ocr_source' => ['nullable', Rule::in(['rapidocr', 'ai_attempted', 'never_ai'])],
            'ai_status' => ['nullable', Rule::in(['never', 'queued', 'processing', 'active', 'resolved', 'human_required', 'non_daily', 'failed', 'skipped', 'failed_or_skipped'])],
            'reason' => ['nullable', Rule::in(array_keys(DailyPhotoExceptionReason::LABELS))],
            'sender' => ['nullable', 'string', 'max:100'],
            'review_status' => ['nullable', Rule::in(['PENDING', 'AUTO_APPROVED', 'APPROVED', 'CORRECTED', 'REJECTED'])],
            'overview_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['PENDING', 'PROCESSING', 'RETRY', 'COMPLETED', 'EXCEPTION', 'FAILED'])],
            'document_type' => ['nullable', Rule::in(['UNKNOWN', 'DAILY_TIMEMARK', 'WEEKLY_JOURNAL', 'IGNORED_HOUR_METER', 'IGNORED_NON_DAILY_PHOTO'])],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
