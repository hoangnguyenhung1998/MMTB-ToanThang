<?php

namespace App\Services;

use App\Models\OcrJob;

class DailyPhotoExceptionReason
{
    public const LABELS = [
        'MACHINE_OCR_INVALID' => 'Mã máy OCR không hợp lệ',
        'MACHINE_NOT_FOUND' => 'Không tìm thấy máy',
        'MACHINE_AMBIGUOUS' => 'Mã máy trùng khóa chuẩn hóa',
        'SENDER_MAPPING_MISSING' => 'Người gửi chưa được ánh xạ máy',
        'CAPTURE_TIME_MISSING' => 'Thiếu giờ chụp',
        'CAPTURE_DATE_MISSING' => 'Thiếu ngày chụp',
        'ASSIGNMENT_AMBIGUOUS' => 'Phân công máy không duy nhất',
        'DUPLICATE_TIMESTAMP' => 'Trùng thời điểm ảnh',
        'PAIRING_AMBIGUOUS' => 'Không thể ghép ảnh an toàn',
        'OCR_RETRY_FAILED' => 'OCR có mục tiêu vẫn không đủ dữ liệu',
        'OTHER' => 'Ngoại lệ khác',
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
        return match ($reason) {
            'LOW_CONFIDENCE' => null,
            'MISSING_DATE' => 'CAPTURE_DATE_MISSING',
            'MISSING_TIME', 'MISSING_CAPTURE_TIME', 'UNCLASSIFIED_TIME', 'AMBIGUOUS_TIME' => 'CAPTURE_TIME_MISSING',
            'MISSING_ASSET_CODE' => 'SENDER_MAPPING_MISSING',
            'UNKNOWN_ASSET_CODE' => filled($job?->observed_asset_code ?? $job?->asset_code)
                ? 'MACHINE_OCR_INVALID'
                : 'SENDER_MAPPING_MISSING',
            'AMBIGUOUS_DATE' => 'CAPTURE_DATE_MISSING',
            'ODD_EVIDENCE_COUNT', 'INVALID_ORDER', 'NEAR_DUPLICATE' => 'PAIRING_AMBIGUOUS',
            'MACHINE_OCR_INVALID', 'MACHINE_NOT_FOUND', 'MACHINE_AMBIGUOUS',
            'SENDER_MAPPING_MISSING', 'CAPTURE_TIME_MISSING', 'CAPTURE_DATE_MISSING',
            'ASSIGNMENT_AMBIGUOUS', 'DUPLICATE_TIMESTAMP', 'PAIRING_AMBIGUOUS',
            'OCR_RETRY_FAILED' => $reason,
            default => 'OTHER',
        };
    }

    public function labels(array $reasons): array
    {
        return collect($reasons)
            ->mapWithKeys(fn (string $reason): array => [$reason => self::LABELS[$reason] ?? self::LABELS['OTHER']])
            ->all();
    }
}
