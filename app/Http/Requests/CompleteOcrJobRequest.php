<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteOcrJobRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i:s'],
            'asset_code' => ['nullable', 'string', 'max:100'],
            'operator_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'work_location' => ['nullable', 'string', 'max:2000'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'raw_text' => ['nullable', 'string'],
            'image_fingerprint' => ['nullable', 'regex:/^[a-f0-9]{16}$/'],
            'candidate_metadata' => ['nullable', 'array'],
            'candidate_metadata.machine_candidates' => ['nullable', 'array', 'max:20'],
            'candidate_metadata.machine_candidates.*' => ['string', 'max:100'],
            'candidate_metadata.machine_evidence' => ['nullable', 'array', 'max:200'],
            'candidate_metadata.machine_evidence.*' => ['array'],
            'candidate_metadata.machine_evidence.*.value' => ['nullable', 'string', 'max:100'],
            'candidate_metadata.machine_evidence.*.accepted' => ['nullable', 'boolean'],
            'candidate_metadata.machine_evidence.*.reason' => ['nullable', 'string', 'max:50'],
            'candidate_metadata.machine_evidence.*.rotation' => ['nullable', 'integer', Rule::in([0, 90, 180, 270])],
            'candidate_metadata.machine_evidence.*.region' => ['nullable', 'string', 'max:30'],
            'candidate_metadata.machine_evidence.*.preprocessing' => ['nullable', 'string', 'max:30'],
            'candidate_metadata.machine_evidence.*.priority' => ['nullable', 'integer', 'between:0,3'],
            'candidate_metadata.machine_evidence.*.source_tier' => ['nullable', 'string', 'max:40'],
            'candidate_metadata.date_candidates' => ['nullable', 'array', 'max:20'],
            'candidate_metadata.date_candidates.*' => ['date_format:Y-m-d'],
            'candidate_metadata.time_candidates' => ['nullable', 'array', 'max:20'],
            'candidate_metadata.time_candidates.*' => ['date_format:H:i:s'],
            'candidate_metadata.conflicts' => ['nullable', 'array', 'max:3'],
            'candidate_metadata.conflicts.*' => [Rule::in(['machine', 'date', 'time'])],
            'candidate_metadata.date_evidence' => ['nullable', 'array', 'max:200'],
            'candidate_metadata.date_evidence.*' => ['array'],
            'candidate_metadata.date_evidence.*.value' => ['nullable', 'date_format:Y-m-d'],
            'candidate_metadata.date_evidence.*.accepted' => ['nullable', 'boolean'],
            'candidate_metadata.date_evidence.*.reason' => ['nullable', 'string', 'max:50'],
            'candidate_metadata.date_evidence.*.rotation' => ['nullable', 'integer', Rule::in([0, 90, 180, 270])],
            'candidate_metadata.date_evidence.*.region' => ['nullable', 'string', 'max:30'],
            'candidate_metadata.date_evidence.*.preprocessing' => ['nullable', 'string', 'max:30'],
            'candidate_metadata.date_evidence.*.priority' => ['nullable', 'integer', 'between:0,3'],
            'candidate_metadata.date_evidence.*.source_tier' => ['nullable', 'string', 'max:40'],
            'candidate_metadata.time_evidence' => ['nullable', 'array', 'max:200'],
            'candidate_metadata.time_evidence.*' => ['array'],
            'candidate_metadata.time_evidence.*.raw' => ['nullable', 'string', 'max:100'],
            'candidate_metadata.time_evidence.*.value' => ['nullable', 'date_format:H:i:s'],
            'candidate_metadata.time_evidence.*.accepted' => ['nullable', 'boolean'],
            'candidate_metadata.time_evidence.*.reason' => ['nullable', 'string', 'max:50'],
            'candidate_metadata.time_evidence.*.rotation' => ['nullable', 'integer', Rule::in([0, 90, 180, 270])],
            'candidate_metadata.time_evidence.*.region' => ['nullable', 'string', 'max:30'],
            'candidate_metadata.time_evidence.*.preprocessing' => ['nullable', 'string', 'max:30'],
            'candidate_metadata.time_evidence.*.priority' => ['nullable', 'integer', 'between:0,3'],
            'candidate_metadata.time_evidence.*.source_tier' => ['nullable', 'string', 'max:40'],
            'candidate_metadata.discarded_date_candidates' => ['nullable', 'array', 'max:20'],
            'candidate_metadata.discarded_date_candidates.*' => ['date_format:Y-m-d'],
            'candidate_metadata.ambiguous_date' => ['nullable', 'boolean'],
            'candidate_metadata.selected_priorities' => ['nullable', 'array'],
            'candidate_metadata.selected_priorities.machine' => ['nullable', 'integer', 'between:0,3'],
            'candidate_metadata.selected_priorities.date' => ['nullable', 'integer', 'between:0,3'],
            'candidate_metadata.selected_priorities.time' => ['nullable', 'integer', 'between:0,3'],
            'candidate_metadata.ocr_pass_count' => ['nullable', 'integer', 'between:0,20'],
            'candidate_metadata.stages_executed' => ['nullable', 'array', 'max:4'],
            'candidate_metadata.stages_executed.*' => [Rule::in([
                'PRIMARY_TIMEMARK_0',
                'OTHER_0_DEG_TIMEMARK',
                '0_DEG_WIDER_FALLBACK',
                'ROTATION_FALLBACK',
            ])],
        ];
    }
}
