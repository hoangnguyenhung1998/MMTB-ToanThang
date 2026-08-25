<?php

namespace App\Services\Reconciliation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ReconciliationTimeAllocator
{
    private const REGULAR_LIMIT_MINUTES = 420;

    public function allocate(Collection|array $sourceRows): array
    {
        $intervals = collect($sourceRows)
            ->map(fn ($row) => $this->normaliseInterval(
                data_get($row, 'start_time'),
                data_get($row, 'end_time')
            ))
            ->filter()
            ->sortBy('start_minute')
            ->values();

        if ($intervals->isEmpty()) {
            return $this->emptyAllocation();
        }

        $morning = $intervals->first();
        $afternoonIndex = $intervals->search(
            fn (array $interval, int $index) => $index > 0 && $interval['start_minute'] >= 780
        );
        $afternoon = $afternoonIndex === false ? null : $intervals->get($afternoonIndex);

        $regularIndexes = collect([0, $afternoonIndex])
            ->filter(fn ($index) => $index !== false && $index !== null)
            ->unique()
            ->values();
        $regularMinutes = $regularIndexes->sum(fn (int $index) => $intervals[$index]['minutes']);

        if ($regularMinutes > self::REGULAR_LIMIT_MINUTES && $afternoon !== null) {
            $excess = $regularMinutes - self::REGULAR_LIMIT_MINUTES;
            $regularEnd = $afternoon['end_minute'] - $excess;
            $intervals[$afternoonIndex] = $this->fromMinutes($afternoon['start_minute'], $regularEnd);
            $intervals->push($this->fromMinutes($regularEnd, $afternoon['end_minute']));
            $regularMinutes = self::REGULAR_LIMIT_MINUTES;
        }

        $overtimeIndexes = $intervals->keys()->diff($regularIndexes)->values();
        if ($regularMinutes < self::REGULAR_LIMIT_MINUTES) {
            [$intervals, $regularIndexes, $overtimeIndexes, $regularMinutes] = $this->topUpRegular(
                $intervals,
                $regularIndexes,
                $overtimeIndexes,
                $regularMinutes
            );
        }

        $regular = $regularIndexes
            ->map(fn (int $index) => $intervals[$index])
            ->sortBy('start_minute')
            ->values();
        $overtime = $overtimeIndexes
            ->map(fn (int $index) => $intervals[$index])
            ->filter(fn (?array $interval) => $interval && $interval['minutes'] > 0)
            ->sortBy('start_minute')
            ->values();

        $allocation = $this->emptyAllocation();
        $allocation = array_merge($allocation, $this->pair('regular_morning', $regular->get(0)));
        $allocation = array_merge($allocation, $this->pair('regular_afternoon', $regular->get(1)));

        foreach ($overtime as $interval) {
            $bucket = match (true) {
                $interval['start_minute'] < 780 => 'overtime_lunch',
                $interval['start_minute'] < 1080 => 'overtime_afternoon',
                default => 'overtime_evening',
            };

            if ($allocation[$bucket.'_start'] === null) {
                $allocation = array_merge($allocation, $this->pair($bucket, $interval));
            }
        }

        $allocation['regular_minutes'] = min($regularMinutes, self::REGULAR_LIMIT_MINUTES);
        $allocation['lunch_minutes'] = $this->pairMinutes($allocation, 'overtime_lunch');
        $allocation['ot_afternoon_minutes'] = $this->pairMinutes($allocation, 'overtime_afternoon');
        $allocation['ot_evening_minutes'] = $this->pairMinutes($allocation, 'overtime_evening');
        $allocation['confirmed_check_in'] = $intervals->min('start');
        $allocation['confirmed_check_out'] = $intervals->sortByDesc('end_minute')->value('end');

        return $allocation;
    }

    public function recalculate(array $data): array
    {
        $data['regular_minutes'] = $this->pairMinutes($data, 'regular_morning')
            + $this->pairMinutes($data, 'regular_afternoon');
        $data['lunch_minutes'] = $this->pairMinutes($data, 'overtime_lunch');
        $data['ot_afternoon_minutes'] = $this->pairMinutes($data, 'overtime_afternoon');
        $data['ot_evening_minutes'] = $this->pairMinutes($data, 'overtime_evening');

        $starts = collect([
            $data['regular_morning_start'] ?? null,
            $data['regular_afternoon_start'] ?? null,
            $data['overtime_lunch_start'] ?? null,
            $data['overtime_afternoon_start'] ?? null,
            $data['overtime_evening_start'] ?? null,
        ])->filter();
        $ends = collect([
            $data['regular_morning_end'] ?? null,
            $data['regular_afternoon_end'] ?? null,
            $data['overtime_lunch_end'] ?? null,
            $data['overtime_afternoon_end'] ?? null,
            $data['overtime_evening_end'] ?? null,
        ])->filter();

        $data['confirmed_check_in'] = $starts->sort()->first();
        $data['confirmed_check_out'] = $ends->sort()->last();

        return $data;
    }

    private function topUpRegular(Collection $intervals, Collection $regularIndexes, Collection $overtimeIndexes, int $regularMinutes): array
    {
        $needed = self::REGULAR_LIMIT_MINUTES - $regularMinutes;

        foreach ($overtimeIndexes as $position => $index) {
            if ($needed <= 0) {
                break;
            }

            $interval = $intervals[$index];
            if ($regularIndexes->count() >= 2) {
                $contiguousRegularIndex = $regularIndexes->first(
                    fn (int $regularIndex) => $intervals[$regularIndex]['end_minute'] === $interval['start_minute']
                );
                if ($contiguousRegularIndex === null) {
                    continue;
                }

                $taken = min($needed, $interval['minutes']);
                $regularInterval = $intervals[$contiguousRegularIndex];
                $intervals[$contiguousRegularIndex] = $this->fromMinutes(
                    $regularInterval['start_minute'],
                    $regularInterval['end_minute'] + $taken
                );
                $regularMinutes += $taken;
                $needed -= $taken;

                if ($taken === $interval['minutes']) {
                    $overtimeIndexes->forget($position);
                } else {
                    $intervals[$index] = $this->fromMinutes(
                        $interval['start_minute'] + $taken,
                        $interval['end_minute']
                    );
                }

                continue;
            }

            $taken = min($needed, $interval['minutes']);
            $regularIndexes->push($index);
            $regularMinutes += $taken;
            $needed -= $taken;

            if ($taken < $interval['minutes']) {
                $regularEnd = $interval['start_minute'] + $taken;
                $intervals[$index] = $this->fromMinutes($interval['start_minute'], $regularEnd);
                $intervals->push($this->fromMinutes($regularEnd, $interval['end_minute']));
                $overtimeIndexes->push($intervals->keys()->last());
            }

            $overtimeIndexes->forget($position);
        }

        return [$intervals, $regularIndexes->values(), $overtimeIndexes->values(), $regularMinutes];
    }

    private function normaliseInterval(?string $start, ?string $end): ?array
    {
        if (!$start || !$end) {
            return null;
        }

        $startMinute = $this->minuteOfDay($start);
        $endMinute = $this->minuteOfDay($end);
        if ($endMinute <= $startMinute) {
            $endMinute += 1440;
        }

        return $this->fromMinutes($startMinute, $endMinute);
    }

    private function fromMinutes(int $start, int $end): array
    {
        return [
            'start' => sprintf('%02d:%02d', intdiv($start % 1440, 60), $start % 60),
            'end' => sprintf('%02d:%02d', intdiv($end % 1440, 60), $end % 60),
            'start_minute' => $start,
            'end_minute' => $end,
            'minutes' => max(0, $end - $start),
        ];
    }

    private function minuteOfDay(string $time): int
    {
        $parsed = CarbonImmutable::createFromFormat('H:i:s', strlen($time) === 5 ? $time.':00' : $time);

        return ($parsed->hour * 60) + $parsed->minute;
    }

    private function pair(string $prefix, ?array $interval): array
    {
        return [
            $prefix.'_start' => $interval['start'] ?? null,
            $prefix.'_end' => $interval['end'] ?? null,
        ];
    }

    private function pairMinutes(array $data, string $prefix): int
    {
        $interval = $this->normaliseInterval($data[$prefix.'_start'] ?? null, $data[$prefix.'_end'] ?? null);

        return $interval['minutes'] ?? 0;
    }

    private function emptyAllocation(): array
    {
        $result = [];
        foreach (['regular_morning', 'regular_afternoon', 'overtime_lunch', 'overtime_afternoon', 'overtime_evening'] as $prefix) {
            $result += $this->pair($prefix, null);
        }

        return $result + [
            'regular_minutes' => null,
            'lunch_minutes' => null,
            'ot_afternoon_minutes' => null,
            'ot_evening_minutes' => null,
            'confirmed_check_in' => null,
            'confirmed_check_out' => null,
        ];
    }
}
