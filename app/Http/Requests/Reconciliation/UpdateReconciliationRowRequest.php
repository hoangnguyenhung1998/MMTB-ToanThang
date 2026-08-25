<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\ReconciliationRow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateReconciliationRowRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $timeFields = [
            'ocr_check_in_raw', 'ocr_check_out_raw',
            'rounded_check_in', 'rounded_check_out',
            'confirmed_check_in', 'confirmed_check_out',
            'gps_check_in', 'gps_check_out',
        ];

        foreach (['regular_morning', 'regular_afternoon', 'overtime_lunch', 'overtime_afternoon', 'overtime_evening'] as $prefix) {
            $timeFields[] = $prefix.'_start';
            $timeFields[] = $prefix.'_end';
        }

        $normalized = [];
        foreach ($timeFields as $field) {
            if ($this->exists($field)) {
                $normalized[$field] = $this->normalizeTime($this->input($field));
            }
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        $row = $this->route('reconciliationRow');

        return $row instanceof ReconciliationRow
            && Gate::allows('update', $row);
    }

    public function rules(): array
    {
        $rules = [
            'return_to' => ['nullable', 'in:period'],
            'submit_action' => ['nullable', 'in:save,quick_confirm'],
            'machine_page' => ['nullable', 'integer', 'min:1'],
            'return_filters' => ['nullable', 'array'],
            'return_filters.q' => ['nullable', 'string', 'max:255'],
            'return_filters.machine_id' => ['nullable', 'integer'],
            'return_filters.project_id' => ['nullable', 'integer'],
            'return_filters.command_center_id' => ['nullable', 'integer'],
            'return_filters.work_date' => ['nullable', 'date'],
            'return_filters.date_from' => ['nullable', 'date'],
            'return_filters.date_to' => ['nullable', 'date'],
            'return_filters.row_status' => ['nullable', 'in:DRAFT,REVIEWED,CONFIRMED,REJECTED'],
            'return_filters.change_type' => ['nullable', 'string', 'max:255'],
            'ocr_check_in_raw' => ['nullable', 'date_format:H:i'],
            'ocr_check_out_raw' => ['nullable', 'date_format:H:i'],
            'rounded_check_in' => ['nullable', 'date_format:H:i'],
            'rounded_check_out' => ['nullable', 'date_format:H:i'],
            'confirmed_check_in' => ['nullable', 'date_format:H:i'],
            'confirmed_check_out' => ['nullable', 'date_format:H:i'],
            'regular_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'lunch_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'ot_afternoon_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'ot_evening_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'gps_check_in' => ['nullable', 'date_format:H:i'],
            'gps_check_out' => ['nullable', 'date_format:H:i'],
            'gps_check_in_diff_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'gps_check_out_diff_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'work_location' => ['nullable', 'string', 'max:255'],
            'work_content' => ['nullable', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        foreach (['regular_morning', 'regular_afternoon', 'overtime_lunch', 'overtime_afternoon', 'overtime_evening'] as $prefix) {
            $rules[$prefix.'_start'] = ['nullable', 'date_format:H:i', 'required_with:'.$prefix.'_end'];
            $rules[$prefix.'_end'] = ['nullable', 'date_format:H:i', 'required_with:'.$prefix.'_start'];
        }

        return $rules;
    }

    private function normalizeTime(mixed $value): mixed
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);
        if (preg_match('/^(\d{1,2})$/', $value, $matches) === 1) {
            $hour = (int) $matches[1];

            return $hour <= 23 ? sprintf('%02d:00', $hour) : $value;
        }

        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $value, $matches) === 1) {
            $hour = (int) $matches[1];

            return $hour <= 23 ? sprintf('%02d:%s', $hour, $matches[2]) : $value;
        }

        return $value;
    }
}
