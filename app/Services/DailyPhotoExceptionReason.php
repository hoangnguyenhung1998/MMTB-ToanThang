<?php

namespace App\Services;

use App\Models\OcrJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DailyPhotoExceptionReason
{
    public const LABELS = [
        'MACHINE_OCR_INVALID' => 'Mã máy OCR không hợp lệ',
        'MACHINE_NOT_FOUND' => 'Không tìm thấy máy',
        'MACHINE_AMBIGUOUS' => 'Mã máy trùng khóa chuẩn hóa',
        'SENDER_MAPPING_MISSING' => 'Người gửi chưa được ánh xạ máy',
        'CAPTURE_TIME_MISSING' => 'Thiếu giờ chụp',
        'CAPTURE_DATE_MISSING' => 'Thiếu ngày chụp',
        'CAPTURE_DATE_AMBIGUOUS' => 'Ngày chụp có nhiều cách hiểu',
        'CAPTURE_TIME_AMBIGUOUS' => 'Giờ chụp có nhiều candidate xung đột',
        'ASSIGNMENT_AMBIGUOUS' => 'Phân công máy không duy nhất',
        'DUPLICATE_TIMESTAMP' => 'Trùng thời điểm ảnh',
        'PAIRING_AMBIGUOUS' => 'Không thể ghép ảnh an toàn',
        'OCR_RETRY_FAILED' => 'OCR có mục tiêu vẫn không đủ dữ liệu',
        'OTHER' => 'Ngoại lệ khác',
    ];

    private const STORED_ALIASES = [
        'MACHINE_OCR_INVALID' => ['MACHINE_OCR_INVALID'],
        'MACHINE_NOT_FOUND' => ['MACHINE_NOT_FOUND'],
        'MACHINE_AMBIGUOUS' => ['MACHINE_AMBIGUOUS'],
        'SENDER_MAPPING_MISSING' => ['SENDER_MAPPING_MISSING', 'MISSING_ASSET_CODE'],
        'CAPTURE_TIME_MISSING' => ['CAPTURE_TIME_MISSING', 'MISSING_TIME', 'MISSING_CAPTURE_TIME', 'UNCLASSIFIED_TIME', 'AMBIGUOUS_TIME'],
        'CAPTURE_DATE_MISSING' => ['CAPTURE_DATE_MISSING', 'MISSING_DATE'],
        'CAPTURE_DATE_AMBIGUOUS' => ['CAPTURE_DATE_AMBIGUOUS', 'AMBIGUOUS_DATE'],
        'CAPTURE_TIME_AMBIGUOUS' => ['CAPTURE_TIME_AMBIGUOUS'],
        'ASSIGNMENT_AMBIGUOUS' => ['ASSIGNMENT_AMBIGUOUS'],
        'DUPLICATE_TIMESTAMP' => ['DUPLICATE_TIMESTAMP'],
        'PAIRING_AMBIGUOUS' => ['PAIRING_AMBIGUOUS', 'ODD_EVIDENCE_COUNT', 'INVALID_ORDER', 'NEAR_DUPLICATE'],
        'OCR_RETRY_FAILED' => ['OCR_RETRY_FAILED'],
        'OTHER' => ['OTHER', 'UNCLASSIFIED_DOCUMENT', 'UNKNOWN_CLASSIFICATION_OCR'],
    ];

    public function forJob(OcrJob $job): array
    {
        return collect($job->exceptions ?? [])
            ->map(fn (string $reason): ?string => $this->normalize($reason, $job))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function normalize(string $reason, ?OcrJob $job = null): ?string
    {
        if ($reason === 'LOW_CONFIDENCE') {
            return null;
        }
        if ($reason === 'UNKNOWN_ASSET_CODE') {
            return filled($job?->observed_asset_code ?? $job?->asset_code)
                ? 'MACHINE_OCR_INVALID'
                : 'SENDER_MAPPING_MISSING';
        }
        foreach (self::STORED_ALIASES as $normalized => $aliases) {
            if (in_array($reason, $aliases, true)) {
                return $normalized;
            }
        }

        return 'OTHER';
    }

    public function labels(array $reasons): array
    {
        return collect($reasons)
            ->mapWithKeys(fn (string $reason): array => [$reason => self::LABELS[$reason] ?? self::LABELS['OTHER']])
            ->all();
    }

    public function applyToQuery(Builder $query, string $reason): Builder
    {
        [$sql, $bindings] = $this->sqlPredicate($reason, $query->getModel()->qualifyColumn('exceptions'));

        return $query->whereRaw($sql, $bindings);
    }

    public function applyAnyToQuery(Builder $query, array $reasons): Builder
    {
        $reasons = collect($reasons)->filter(fn (string $reason): bool => isset(self::LABELS[$reason]))->unique()->values();

        return $query->where(function (Builder $nested) use ($reasons): void {
            foreach ($reasons as $index => $reason) {
                [$sql, $bindings] = $this->sqlPredicate($reason, $nested->getModel()->qualifyColumn('exceptions'));
                $index === 0 ? $nested->whereRaw($sql, $bindings) : $nested->orWhereRaw($sql, $bindings);
            }
        });
    }

    public function counts(Builder $query): array
    {
        $selects = [];
        $bindings = [];
        foreach (array_keys(self::LABELS) as $index => $reason) {
            [$sql, $reasonBindings] = $this->sqlPredicate($reason, $query->getModel()->qualifyColumn('exceptions'));
            $selects[] = "SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) AS reason_{$index}";
            array_push($bindings, ...$reasonBindings);
        }

        $row = $query->toBase()->selectRaw(implode(', ', $selects), $bindings)->first();

        return collect(array_keys(self::LABELS))->mapWithKeys(
            fn (string $reason, int $index): array => [$reason => (int) ($row->{"reason_{$index}"} ?? 0)]
        )->all();
    }

    private function sqlPredicate(string $reason, string $column): array
    {
        $aliases = self::STORED_ALIASES[$reason] ?? [$reason];
        $driver = DB::connection()->getDriverName();
        $jsonSql = $driver === 'sqlite'
            ? 'EXISTS (SELECT 1 FROM json_each('.$column.') WHERE json_each.value IN ('.implode(',', array_fill(0, count($aliases), '?')).') )'
            : '('.implode(' OR ', array_fill(0, count($aliases), "JSON_CONTAINS(COALESCE({$column}, JSON_ARRAY()), JSON_QUOTE(?))")).')';
        $parts = [$jsonSql];
        if (in_array($reason, ['MACHINE_OCR_INVALID', 'SENDER_MAPPING_MISSING'], true)) {
            $unknownAssetSql = $driver === 'sqlite'
                ? 'EXISTS (SELECT 1 FROM json_each('.$column.') WHERE json_each.value = ?)'
                : "JSON_CONTAINS(COALESCE({$column}, JSON_ARRAY()), JSON_QUOTE(?))";
            $assetColumn = str_replace('exceptions', 'asset_code', $column);
            $observedAssetColumn = str_replace('exceptions', 'observed_asset_code', $column);
            $hasAsset = "COALESCE(NULLIF(TRIM({$observedAssetColumn}), ''), NULLIF(TRIM({$assetColumn}), '')) IS NOT NULL";
            $parts[] = "({$unknownAssetSql} AND ".($reason === 'MACHINE_OCR_INVALID' ? $hasAsset : "NOT ({$hasAsset})").')';
            $aliases[] = 'UNKNOWN_ASSET_CODE';
        }
        if ($reason === 'CAPTURE_DATE_MISSING') {
            $parts[] = str_replace('exceptions', 'extracted_date', $column).' IS NULL';
        }
        if ($reason === 'CAPTURE_TIME_MISSING') {
            $parts[] = str_replace('exceptions', 'extracted_time', $column).' IS NULL';
        }

        return ['('.implode(' OR ', $parts).')', $aliases];
    }
}
