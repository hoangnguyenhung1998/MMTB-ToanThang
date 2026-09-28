<?php

namespace App\Http\Requests;

use App\Services\DailyPhotoExceptionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewDailyPhotoAiRescueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_groups' => ['required', 'array', 'min:1'],
            'reason_groups.*' => ['string', Rule::in(array_keys(DailyPhotoExceptionReason::LABELS))],
        ];
    }
}
