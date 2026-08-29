<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteMachineHandoverOcrRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'string', 'max:100'], 'confidence' => ['required', 'numeric', 'between:0,1'],
            'extraction' => ['required', 'array'], 'extraction.asset_code' => ['nullable', 'string', 'max:255'],
            'extraction.handover_date' => ['nullable', 'date_format:Y-m-d'], 'extraction.project_text' => ['nullable', 'string', 'max:255'],
            'extraction.command_center_text' => ['nullable', 'string', 'max:255'], 'extraction.machine_type' => ['nullable', 'string', 'max:255'],
            'extraction.model_name' => ['nullable', 'string', 'max:255'], 'extraction.meter_hours' => ['nullable', 'numeric', 'min:0'],
            'extraction.gps_status' => ['nullable', 'string', 'max:255'], 'extraction.handover_people' => ['nullable', 'array'],
            'extraction.technical_findings' => ['nullable', 'array'], 'review_flags' => ['nullable', 'array'],
            'review_flags.*' => ['string', 'max:100'], 'raw_text' => ['nullable', 'string'],
        ];
    }
}
