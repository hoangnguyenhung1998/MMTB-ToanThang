<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutomationHeartbeatRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'agent_version' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
            'services' => ['required', 'array', 'min:1', 'max:20'],
            'services.*.service_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.service_type' => ['required', Rule::in([
                'ZALO_COLLECTOR', 'OCR_WORKER', 'JOURNAL_WORKER',
                'RECONCILIATION_WORKER', 'OPENCLAW_GATEWAY', 'OTHER',
            ])],
            'services.*.status' => ['required', Rule::in(['HEALTHY', 'DEGRADED', 'PAUSED'])],
            'services.*.version' => ['nullable', 'string', 'max:50'],
            'services.*.current_job' => ['nullable', 'string', 'max:255'],
            'services.*.queue_depth' => ['nullable', 'integer', 'min:0'],
            'services.*.consecutive_errors' => ['nullable', 'integer', 'min:0'],
            'services.*.last_success_at' => ['nullable', 'date'],
            'services.*.last_api_success_at' => ['nullable', 'date'],
            'services.*.last_job_success_at' => ['nullable', 'date'],
            'services.*.current_job_started_at' => ['nullable', 'date'],
            'services.*.error_code' => ['nullable', 'string', 'max:100'],
            'services.*.error_message' => ['nullable', 'string', 'max:2000'],
            'services.*.metrics' => ['nullable', 'array'],
        ];
    }
}
