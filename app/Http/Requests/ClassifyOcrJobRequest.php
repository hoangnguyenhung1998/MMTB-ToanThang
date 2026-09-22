<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassifyOcrJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'attempt' => [Rule::requiredIf((bool) config('ocr.enforce_attempt_fencing')), 'nullable', 'integer', 'min:1'],
            'document_type' => ['required', 'string', 'in:UNKNOWN,DAILY_TIMEMARK,WEEKLY_JOURNAL,IGNORED_HOUR_METER,IGNORED_NON_DAILY_PHOTO'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'raw_text' => ['nullable', 'string'],
            'classification_metadata' => ['nullable', 'array'],
            'classification_metadata.reason' => ['nullable', 'string', 'max:100'],
            'classification_metadata.semantic_markers' => ['nullable', 'array', 'max:10'],
            'classification_metadata.semantic_markers.*' => ['string', 'max:50'],
            'classification_metadata.counter_token_count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'classification_metadata.structure_score' => ['nullable', 'numeric', 'between:0,1'],
            'classification_metadata.matched_phrase' => ['nullable', 'string', 'max:100'],
        ];
    }
}
