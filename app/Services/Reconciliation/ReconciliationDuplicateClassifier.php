<?php

namespace App\Services\Reconciliation;

/** Pure, conservative payload proof shared by Repair and the read-only audit. */
class ReconciliationDuplicateClassifier
{
    private const ALLOCATION = ['rounded_check_in', 'rounded_check_out', 'confirmed_check_in', 'confirmed_check_out',
        'regular_morning_start', 'regular_morning_end', 'regular_afternoon_start', 'regular_afternoon_end',
        'overtime_lunch_start', 'overtime_lunch_end', 'overtime_afternoon_start', 'overtime_afternoon_end',
        'overtime_evening_start', 'overtime_evening_end', 'regular_minutes', 'lunch_minutes', 'ot_afternoon_minutes', 'ot_evening_minutes'];

    /** Whole-row preservation only: the other row has no independent interval/time bundle. */
    public function sameSourceShadow(object $rich, object $shadow): bool
    {
        if ($this->protected($rich) || $this->protected($shadow)) {
            return false;
        }
        $left = $this->payload($rich);
        $right = $this->payload($shadow);
        if (empty($left['daily_intervals']) || ! empty($right['daily_intervals']) || ! empty($left['journal_row_ids']) || ! empty($right['journal_row_ids'])) {
            return false;
        }
        $a = array_intersect_key($left, array_flip(self::ALLOCATION));
        $b = array_intersect_key($right, array_flip(self::ALLOCATION));
        if ($a !== $b) {
            if (array_filter($b, fn ($v) => $v !== null)) {
                return false;
            }
            // Explicit zero is populated allocation, including on the rich side.
            foreach ($a as $field => $value) {
                if (in_array($value, [0, '0'], true) && ($b[$field] ?? null) !== $value) {
                    return false;
                }
            }
        }
        $ignored = array_flip([...self::ALLOCATION, 'daily_intervals']);

        return array_diff_key($left, $ignored) === array_diff_key($right, $ignored);
    }

    /** Ownership-only consolidation, after the caller proves the canonical source.
     * Zero/NULL on a shadow is not a competing allocation. Never fill/recompute survivor hours.
     * Evidence references and independent, non-conflicting descriptors may be carried across.
     */
    public function ownershipShadowChanges(object $rich, object $shadow): ?array
    {
        if ($this->protected($rich) || $this->protected($shadow)) {
            return null;
        }
        $left = $this->payload($rich);
        $right = $this->payload($shadow);
        if (empty($left['daily_intervals']) || ! empty($left['journal_row_ids']) || ! empty($right['journal_row_ids'])) {
            return null;
        }
        foreach (self::ALLOCATION as $field) {
            $a = $left[$field] ?? null;
            $b = $right[$field] ?? null;
            if ($a !== $b && ! in_array($b, [null, 0, '0'], true)) {
                return null;
            }
        }
        $changes = [];
        $fillable = ['driver_id', 'work_location', 'work_content', 'explanation', 'notes',
            'gps_check_in', 'gps_check_out', 'gps_check_in_diff_minutes', 'gps_check_out_diff_minutes',
            'ocr_check_in_raw', 'ocr_check_out_raw'];
        $ignored = [...self::ALLOCATION, 'daily_intervals', 'daily_ocr_job_ids'];
        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $field) {
            if (in_array($field, $ignored, true)) {
                continue;
            }
            $a = $left[$field] ?? null;
            $b = $right[$field] ?? null;
            if ($a === $b || $b === null) {
                continue;
            }
            if ($a === null && in_array($field, $fillable, true)) {
                $changes[$field] = $shadow->$field;
            } else {
                return null;
            }
        }
        $ids = array_values(array_unique([...($left['daily_ocr_job_ids'] ?? []), ...($right['daily_ocr_job_ids'] ?? [])]));
        sort($ids);
        $parts = [];
        foreach ([...($left['daily_intervals'] ?? []), ...($right['daily_intervals'] ?? [])] as $part) {
            $id = $part['canonical_interval_id'] ?? null;
            if (! $id || (isset($parts[$id]) && $parts[$id] !== $part)) {
                return null;
            }
            $parts[$id] = $part;
        }
        if ($ids !== ($left['daily_ocr_job_ids'] ?? [])) {
            $changes['daily_ocr_job_ids'] = json_encode($ids, JSON_THROW_ON_ERROR);
        }
        if (array_values($parts) !== ($left['daily_intervals'] ?? [])) {
            $changes['daily_intervals'] = json_encode(array_values($parts), JSON_THROW_ON_ERROR);
        }

        return $changes;
    }

    public const TECHNICAL = ['id', 'machine_assignment_id', 'project_id', 'command_center_id',
        'segment_start', 'segment_end', 'created_at', 'updated_at', 'change_type', 'change_note',
        'evidence_signature', 'evidence_synced_at', 'evidence_summary', 'evidence_status'];

    public function protected(object $row): bool
    {
        return ($row->status ?? 'DRAFT') !== 'DRAFT'
            || ! empty($row->reviewed_at) || ! empty($row->confirmed_at) || ! empty($row->manually_edited_at)
            || ! empty($row->reviewed_by) || ! empty($row->confirmed_by);
    }

    public function hasEvidence(object $row): bool
    {
        $ignored = ['id', 'reconciliation_period_id', 'machine_id', 'machine_assignment_id', 'work_date',
            'project_id', 'command_center_id', 'segment_start', 'segment_end', 'status', 'created_at', 'updated_at',
            'change_type', 'change_note', 'evidence_status'];
        foreach ((array) $row as $field => $value) {
            if (! in_array($field, $ignored, true) && ! in_array($value, [null, '', 0, '0', '[]'], true)) {
                return true;
            }
        }

        return ! in_array($row->evidence_status ?? null, [null, '', 'NO_EVIDENCE'], true);
    }

    public function payload(object $row): array
    {
        $payload = array_diff_key((array) $row, array_flip(self::TECHNICAL));
        foreach ($payload as $field => &$value) {
            if (in_array($field, ['daily_ocr_job_ids', 'journal_row_ids', 'daily_intervals'], true)) {
                $value = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
                $value ??= [];
                if ($field !== 'daily_intervals') {
                    sort($value);
                }
            }
            if ($value === '' || $value === []) {
                $value = null;
            }
        }
        unset($value);
        ksort($payload);

        return $payload;
    }

    public function key(object $row): string
    {
        return hash('sha256', json_encode($this->payload($row), JSON_THROW_ON_ERROR));
    }

    public function compare(object $source, object $target): array
    {
        $left = $this->payload($source);
        $right = $this->payload($target);
        $differences = [];
        $changes = [];
        $conflicts = [];
        // Only independent descriptive fields can be filled. Time/evidence bundles stay atomic.
        $fillable = ['driver_id', 'work_location', 'work_content', 'explanation', 'notes'];
        foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $field) {
            $a = $left[$field] ?? null;
            $b = $right[$field] ?? null;
            if ($a === $b) {
                continue;
            }
            $differences[] = $field;
            if (in_array($field, $fillable, true) && ($a === null || $b === null)) {
                if ($b === null) {
                    $changes[$field] = ((array) $source)[$field];
                }
            } else {
                $conflicts[] = $field;
            }
        }
        $protected = $this->protected($source) || $this->protected($target);
        $category = $protected || $conflicts ? 'D' : ($differences ? 'C' : 'B');
        $technical = [];
        foreach (self::TECHNICAL as $field) {
            if (($source->$field ?? null) !== ($target->$field ?? null)) {
                $technical[] = $field;
            }
        }

        return ['category' => $category, 'protected' => $protected, 'business_fields' => $differences,
            'conflicting_fields' => $conflicts, 'technical_fields' => $technical,
            'changes' => $category === 'C' ? $changes : []];
    }
}
