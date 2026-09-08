<?php

namespace App\Services\Reconciliation;

use Illuminate\Validation\ValidationException;

/** Explicit shift types preserve the SOP: lunch/evening never top up HC. */
class DailyTimeAllocator
{
    public const KINDS = ['regular_morning', 'regular_afternoon', 'overtime_lunch', 'overtime_afternoon', 'overtime_evening'];

    public function minute(string $time): int
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) {
            throw ValidationException::withMessages(['intervals' => 'Giờ phải có định dạng 24 giờ HH:mm.']);
        }
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    public function format(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute % 1440, 60), $minute % 60);
    }

    public function round(int $minute, bool $entry): int
    {
        $base = intdiv($minute, 30) * 30;
        return $entry && $minute - $base > 10 ? $base + 30 : $base;
    }

    public function allocate(array $intervals, bool $round = true, int $regularLimit = 420): array
    {
        $result = ['regular_minutes' => 0, 'lunch_minutes' => 0, 'ot_afternoon_minutes' => 0, 'ot_evening_minutes' => 0,
            'confirmed_check_in' => null, 'confirmed_check_out' => null];
        foreach (self::KINDS as $kind) {
            $result[$kind.'_start'] = null;
            $result[$kind.'_end'] = null;
        }
        $segments = [];
        foreach ($intervals as $interval) {
            $kind = $interval['kind'] ?? '';
            if (!in_array($kind, self::KINDS, true)) $this->invalid('Cần xác nhận loại ca trước khi phân bổ.');
            $start = $this->minute($interval['start']);
            $end = $this->minute($interval['end']);
            if ($end < $start && $kind === 'overtime_evening') $end += 1440;
            if ($round) {
                $start = $this->round($start, true);
                $end = $this->round($end, false);
            }
            if ($start >= 1440) $this->invalid('Giờ vào sau làm tròn thuộc ngày kế tiếp. Chọn dòng đúng ngày làm việc.');
            if ($end <= $start) $this->invalid('Ca không còn thời gian hợp lệ sau làm tròn; cần kiểm tra ảnh.');
            $segments[] = compact('kind', 'start', 'end');
        }
        usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);
        $lastEnd = null;
        $buckets = [];
        foreach ($segments as $segment) {
            ['kind' => $kind, 'start' => $start, 'end' => $end] = $segment;
            if ($lastEnd !== null && $start < $lastEnd) $this->invalid('Các ca bị chồng giờ.');
            $lastEnd = $end;
            if (str_starts_with($kind, 'regular_')) {
                $taken = min($end - $start, max(0, min(420, $regularLimit) - $result['regular_minutes']));
                if ($taken > 0) $buckets[$kind][] = [$start, $start + $taken];
                $result['regular_minutes'] += $taken;
                if ($start + $taken < $end) $buckets['overtime_afternoon'][] = [$start + $taken, $end];
            } else {
                $buckets[$kind][] = [$start, $end];
            }
        }
        foreach ($buckets as $kind => $parts) {
            usort($parts, fn ($a, $b) => $a[0] <=> $b[0]);
            for ($i = 1; $i < count($parts); $i++) {
                if ($parts[$i - 1][1] !== $parts[$i][0]) $this->invalid('Nhiều khoảng rời rạc cùng loại ca: cần kiểm tra riêng, không nối qua giờ nghỉ.');
            }
            $result[$kind.'_start'] = $this->format($parts[0][0]);
            $result[$kind.'_end'] = $this->format($parts[count($parts) - 1][1]);
            $minutesKey = ['overtime_lunch' => 'lunch_minutes', 'overtime_afternoon' => 'ot_afternoon_minutes', 'overtime_evening' => 'ot_evening_minutes'][$kind] ?? null;
            if ($minutesKey) $result[$minutesKey] = array_sum(array_map(fn ($part) => $part[1] - $part[0], $parts));
        }
        if ($segments) {
            $result['confirmed_check_in'] = $this->format($segments[0]['start']);
            $result['confirmed_check_out'] = $this->format($segments[count($segments) - 1]['end']);
        }
        return $result;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['intervals' => $message]);
    }

    public function assertWithinAssignment(array $allocation, \App\Models\ReconciliationRow $row): void
    {
        foreach (self::KINDS as $kind) {
            if (empty($allocation[$kind.'_start'])) continue;
            $start = $row->work_date->copy()->setTimeFromTimeString($allocation[$kind.'_start']);
            $end = $row->work_date->copy()->setTimeFromTimeString($allocation[$kind.'_end']);
            if ($end->lt($start) && $kind === 'overtime_evening') $end->addDay();
            $assignment = $row->assignment;
            if (!$assignment || $start->lt($assignment->time_in) || ($assignment->time_out && $end->gt($assignment->time_out))) {
                $this->invalid('Giờ tính công vượt khoảng phân công máy/BCH. Kiểm tra ca và lịch điều chuyển.');
            }
        }
        $otherRows = $row->period->rows()->where('machine_id', $row->machine_id)->whereKeyNot($row->id)
            ->whereBetween('work_date', [$row->work_date->copy()->subDay()->toDateString(), $row->work_date->copy()->addDay()->toDateString()])->get();
        foreach ($otherRows as $other) {
            foreach ($this->ranges($allocation, $row->work_date) as [$start, $end]) {
                foreach ($this->ranges($other->toArray(), $other->work_date) as [$otherStart, $otherEnd]) {
                    if ($start < $otherEnd && $otherStart < $end) $this->invalid('Giờ máy đã được tính ở dòng BCH hoặc ca qua đêm khác. Kiểm tra các dòng liên quan.');
                }
            }
        }
        if (($allocation['regular_minutes'] ?? 0) > $this->remainingRegularMinutes($row)) {
            $this->invalid('Tổng giờ hành chính của máy trong ngày vượt 7 tiếng ở các dòng BCH.');
        }
    }

    public function remainingRegularMinutes(\App\Models\ReconciliationRow $row): int
    {
        return max(0, 420 - (int) $row->period->rows()->where('machine_id', $row->machine_id)
            ->whereDate('work_date', $row->work_date)->whereKeyNot($row->id)->sum('regular_minutes'));
    }

    private function ranges(array $values, $date): array
    {
        $ranges = [];
        foreach (self::KINDS as $kind) {
            if (empty($values[$kind.'_start']) || empty($values[$kind.'_end'])) continue;
            $start = $date->copy()->setTimeFromTimeString($values[$kind.'_start']);
            $end = $date->copy()->setTimeFromTimeString($values[$kind.'_end']);
            if ($kind === 'overtime_evening' && $end->lt($start)) $end->addDay();
            $ranges[] = [$start, $end];
        }
        return $ranges;
    }
}
