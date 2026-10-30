<?php

namespace App\Services\Dossiers;

use App\Models\DossierRecord;
use App\Models\DossierVersion;
use Carbon\CarbonImmutable;

class DossierStatus
{
    public const LABELS = [
        'missing' => 'Thiếu giấy bắt buộc', 'review' => 'Chưa xác định hạn / cần kiểm tra',
        'future' => 'Chưa có hiệu lực', 'valid' => 'Còn hiệu lực', 'expiring' => 'Sắp hết hạn',
        'expired' => 'Hết hạn', 'permanent' => 'Không thời hạn', 'archived' => 'Đã lưu trữ',
        'superseded' => 'Đã thay thế',
    ];

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('dossiers.timezone'));
    }

    public function current(DossierRecord $record, ?CarbonImmutable $at = null): ?DossierVersion
    {
        $at ??= $this->now();

        // An expired or archived latest version never silently falls back to an older one.
        return $record->versions->where('confirmed', true)
            ->filter(fn ($v) => $v->effective_at <= $at->format('Y-m-d H:i:s'))
            ->sortByDesc('effective_at')->first();
    }

    public function expiry(DossierVersion $v): ?CarbonImmutable
    {
        $tz = config('dossiers.timezone');

        return match ($v->expiry_precision) {
            'day' => CarbonImmutable::parse($v->expiry_value, $tz)->endOfDay(),
            'minute' => CarbonImmutable::parse($v->expiry_value, $tz),
            'month' => $v->month_rule === 'end_of_month'
                ? CarbonImmutable::parse($v->expiry_value.'-01', $tz)->endOfMonth()->endOfDay() : null,
            default => null,
        };
    }

    public function describe(?DossierVersion $v, array $warningDays = [], ?CarbonImmutable $at = null): array
    {
        $at ??= $this->now();
        $end = $v ? $this->expiry($v) : null;
        $days = $end ? (int) $at->startOfDay()->diffInDays($end->startOfDay(), false) : null;
        $status = match (true) {
            ! $v => 'missing',
            $v->archived => 'archived',
            ! $v->confirmed => 'review',
            $v->effective_at > $at->format('Y-m-d H:i:s') => 'future',
            $v->expiry_precision === 'permanent' => 'permanent',
            ! $end => 'review',
            $at->greaterThan($end) => 'expired',
            $days <= max($warningDays ?: [0]) => 'expiring',
            default => 'valid',
        };

        return ['status' => $status, 'label' => self::LABELS[$status], 'days' => $days, 'expires_at' => $end?->format('Y-m-d H:i:s')];
    }
}
