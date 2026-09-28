<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteDailyPhotoAiRescueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'],
            'attempt' => ['required', 'integer', 'min:1'],
            'provider' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:150'],
            'prompt_version' => ['required', 'string', 'max:100'],
            'schema_version' => ['required', 'string', 'max:100'],
            'result' => ['required', 'array'],
            'result.classification' => ['required', Rule::in([
                'DAILY_PHOTO',
                'NON_DAILY_HOUR_METER',
                'NON_DAILY_OTHER',
                'UNKNOWN',
            ])],
            'result.machine' => ['nullable', 'string', 'max:100'],
            'result.capture_date' => ['nullable', 'date_format:Y-m-d'],
            'result.capture_time' => ['nullable', 'date_format:H:i'],
            'result.ambiguities' => ['present', 'array', 'max:20'],
            'result.ambiguities.*' => ['string', 'max:500'],
            'raw_response' => ['nullable', 'string', 'max:100000'],
            'usage' => ['nullable', 'array'],
            'usage.prompt_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.completion_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.total_tokens' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
