<?php

namespace App\Services\Reconciliation;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

class ReconciliationOcrSpreadsheetParser
{
    public const WORKSHEET = 'Sheet1';

    private const HEADER_ALIASES = [
        'key' => ['key'],
        'source_bch' => ['bch'],
        'machine_code' => ['ma tai san'],
        'work_date' => ['ngay'],
        'pm_start' => ['pm bd'],
        'pm_end' => ['pm kt'],
        'nt_morning_start' => ['nt sang bd'],
        'nt_morning_end' => ['nt sang kt'],
        'nt_afternoon_start' => ['nt chieu bd'],
        'nt_afternoon_end' => ['nt chieu kt'],
        'tc_noon_start' => ['tc trua bd'],
        'tc_noon_end' => ['tc trua kt'],
        'tc_afternoon_start' => ['tc chieu bd'],
        'tc_afternoon_end' => ['tc chieu kt'],
        'tc_night_start' => ['tc toi bd'],
        'tc_night_end' => ['tc toi kt'],
        'location' => ['vi tri thi cong'],
        'explanation' => ['loi giai trinh'],
        'work_content' => ['noi dung cv'],
    ];

    private const INTERVALS = [
        'pm' => ['label' => 'PM', 'start' => 'pm_start', 'end' => 'pm_end'],
        'nt_morning' => ['label' => 'NT sáng', 'start' => 'nt_morning_start', 'end' => 'nt_morning_end'],
        'nt_afternoon' => ['label' => 'NT chiều', 'start' => 'nt_afternoon_start', 'end' => 'nt_afternoon_end'],
        'tc_noon' => ['label' => 'TC trưa', 'start' => 'tc_noon_start', 'end' => 'tc_noon_end'],
        'tc_afternoon' => ['label' => 'TC chiều', 'start' => 'tc_afternoon_start', 'end' => 'tc_afternoon_end'],
        'tc_night' => ['label' => 'TC tối', 'start' => 'tc_night_start', 'end' => 'tc_night_end'],
    ];

    public function parse(UploadedFile $file): array
    {
        try {
            $workbook = IOFactory::load($file->getRealPath());
        } catch (\Throwable) {
            throw new RuntimeException('Không đọc được file OCR. Vui lòng kiểm tra định dạng Excel/CSV.');
        }

        $worksheet = $workbook->getSheetByName(self::WORKSHEET);

        if (! $worksheet) {
            throw new RuntimeException('File OCR hợp lệ phải có sheet Sheet1 theo mẫu ứng dụng OCR.');
        }

        $rows = collect($worksheet->toArray(null, true, false, false))
            ->filter(fn (array $row) => collect($row)->filter(fn ($value) => filled($value))->isNotEmpty())
            ->values();

        if ($rows->count() < 2) {
            throw new RuntimeException('Sheet1 không có dữ liệu để nhập.');
        }

        $headers = $this->mapHeaders($rows->first());

        foreach (['machine_code', 'work_date'] as $requiredHeader) {
            if (! isset($headers[$requiredHeader])) {
                throw new RuntimeException('Sheet1 cần có cột Mã Tài Sản và NGÀY.');
            }
        }

        return [
            'worksheet' => self::WORKSHEET,
            'rows' => $rows->skip(1)->values()->map(function (array $row, int $index) use ($headers): array {
                $intervals = $this->intervals($row, $headers);

                return [
                    'source_row' => $index + 2,
                    'key' => $this->cleanText($this->value($row, $headers, 'key')),
                    'source_bch' => $this->cleanText($this->value($row, $headers, 'source_bch')),
                    'machine_code' => $this->normalizeMachineCode($this->value($row, $headers, 'machine_code')),
                    'work_date' => $this->parseDate($this->value($row, $headers, 'work_date')),
                    'intervals' => $intervals,
                    'has_working_time_data' => collect($intervals)->contains(fn (array $interval) => $interval['has_source_data']),
                    'location' => $this->cleanText($this->value($row, $headers, 'location')),
                    'explanation' => $this->cleanText($this->value($row, $headers, 'explanation')),
                    'work_content' => $this->cleanText($this->value($row, $headers, 'work_content')),
                ];
            })->all(),
        ];
    }

    private function mapHeaders(array $headerRow): array
    {
        $mapped = [];

        foreach ($headerRow as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (in_array($normalized, $aliases, true)) {
                    $mapped[$field] = $index;
                    break;
                }
            }
        }

        return $mapped;
    }

    private function intervals(array $row, array $headers): array
    {
        return collect(self::INTERVALS)->map(function (array $interval, string $key) use ($row, $headers): array {
            $startValue = $this->value($row, $headers, $interval['start']);
            $endValue = $this->value($row, $headers, $interval['end']);

            return [
                'key' => $key,
                'label' => $interval['label'],
                'start' => $this->parseTime($startValue),
                'end' => $this->parseTime($endValue),
                'has_source_data' => $this->hasCellValue($startValue) || $this->hasCellValue($endValue),
            ];
        })->filter(fn (array $interval) => $interval['has_source_data'])->values()->all();
    }

    private function value(array $row, array $headers, string $field): mixed
    {
        return isset($headers[$field]) ? ($row[$headers[$field]] ?? null) : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        $text = trim((string) $value);

        foreach (['d/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'Y-m-d'] as $format) {
            try {
                return Carbon::createFromFormat($format, $text)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function parseTime(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('H:i');
        }

        $text = trim((string) $value);

        foreach (['H:i', 'G:i', 'H:i:s', 'G:i:s'] as $format) {
            try {
                return Carbon::createFromFormat($format, $text)->format('H:i');
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function normalizeMachineCode(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Str::of((string) $value)
            ->replaceMatches('/\s+/u', ' ')
            ->replace(['–', '—'], '-')
            ->trim()
            ->toString();
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)
            ->ascii()
            ->lower()
            ->replace(['đ', 'Đ'], 'd')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    private function cleanText(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }

    private function hasCellValue(mixed $value): bool
    {
        return ! is_null($value) && trim((string) $value) !== '';
    }
}
