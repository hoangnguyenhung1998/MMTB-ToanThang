<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationRow;
use Carbon\Carbon;

class ReconciliationCalculator
{
    public function summaryFor(ReconciliationRow $row): array
    {
        $logbookMinutes = $this->logbookMinutes($row);
        $gpsMinutes = $this->gpsMinutes($row);
        $differenceMinutes = $logbookMinutes !== null && $gpsMinutes !== null
            ? $logbookMinutes - $gpsMinutes
            : null;
        $absoluteDifferenceMinutes = $differenceMinutes !== null
            ? abs($differenceMinutes)
            : null;

        return [
            'logbook_minutes' => $logbookMinutes,
            'gps_minutes' => $gpsMinutes,
            'difference_minutes' => $differenceMinutes,
            'absolute_difference_minutes' => $absoluteDifferenceMinutes,
            'variance' => $this->variance($absoluteDifferenceMinutes),
        ];
    }

    public function logbookMinutes(ReconciliationRow $row): ?int
    {
        $minutes = collect([
            $row->regular_minutes,
            $row->lunch_minutes,
            $row->ot_afternoon_minutes,
            $row->ot_evening_minutes,
        ])->filter(fn ($value) => $value !== null);

        return $minutes->isEmpty()
            ? null
            : (int) $minutes->sum();
    }

    public function gpsMinutes(ReconciliationRow $row): ?int
    {
        if (!$row->gps_check_in || !$row->gps_check_out) {
            return null;
        }

        $checkIn = Carbon::parse($row->work_date->toDateString().' '.$row->gps_check_in);
        $checkOut = Carbon::parse($row->work_date->toDateString().' '.$row->gps_check_out);

        if ($checkOut->lt($checkIn)) {
            $checkOut->addDay();
        }

        return (int) max(0, $checkIn->diffInMinutes($checkOut));
    }

    public function variance(?int $absoluteDifferenceMinutes): ?string
    {
        if ($absoluteDifferenceMinutes === null) {
            return null;
        }

        return match (true) {
            $absoluteDifferenceMinutes === 0 => 'MATCHED',
            $absoluteDifferenceMinutes <= 15 => 'MINOR',
            $absoluteDifferenceMinutes <= 60 => 'REVIEW_REQUIRED',
            default => 'ABNORMAL',
        };
    }
}
