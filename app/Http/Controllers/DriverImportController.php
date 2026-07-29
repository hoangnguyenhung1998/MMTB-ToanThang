<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\Driver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DriverImportController extends Controller
{
    private const HEADER = [
        'ho_ten',
        'so_dien_thoai',
        'so_cccd',
    ];

    public function form(): View
    {
        return view('drivers.import');
    }

    public function template(): BinaryFileResponse
    {
        $rows = [
            self::HEADER,
            ['Nguyễn Văn A', '0901234567', '012345678901'],
            ['Trần Thị B', '0912345678', ''],
        ];

        return Excel::download(new ArrayExport($rows), 'mau-import-tai-xe.xlsx');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $rows = Excel::toArray([], $request->file('file'))[0] ?? [];
        if (count($rows) === 0) {
            return back()->with('import_errors', ['File không có dữ liệu.']);
        }

        $displayErrors = [];
        $rowErrors = [];
        $header = $this->normalizeHeaderRow($rows[0] ?? []);

        foreach (self::HEADER as $index => $expected) {
            $actual = $header[$index] ?? '';
            if ($actual !== $expected) {
                $this->addError(
                    $displayErrors,
                    $rowErrors,
                    0,
                    1,
                    $expected,
                    "Sai tiêu đề, cần '{$expected}'"
                );
            }
        }

        $seenCccd = [];
        $parsedRows = [];

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $normalized = $this->normalizeDataRow($row);
            $mapped = [];
            foreach (self::HEADER as $colIndex => $key) {
                $mapped[$key] = $normalized[$colIndex] ?? '';
            }

            if ($this->isRowEmpty($mapped)) {
                continue;
            }

            $rowNumber = $index + 1;
            $name = $mapped['ho_ten'];
            $cccd = $mapped['so_cccd'];

            if ($name === '') {
                $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'ho_ten', 'Bắt buộc');
            }

            if ($cccd !== '') {
                $cccdKey = Str::of($cccd)->trim()->toString();
                if (!preg_match('/^\d{9,12}$/', $cccdKey)) {
                    $this->addError(
                        $displayErrors,
                        $rowErrors,
                        $index,
                        $rowNumber,
                        'so_cccd',
                        'Chỉ cho phép số từ 9 đến 12 ký tự'
                    );
                }

                if (isset($seenCccd[$cccdKey])) {
                    $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'so_cccd', 'Trùng trong file');
                }
                $seenCccd[$cccdKey] = true;
            }

            $parsedRows[] = [
                'row_index' => $index,
                'row_number' => $rowNumber,
                'data' => $mapped,
            ];
        }

        if ($parsedRows === []) {
            $errorPath = $this->storeErrorFile($rows, $rowErrors, 'drivers');

            return back()
                ->with('import_errors', ['File không có dữ liệu hợp lệ.'])
                ->with('error_file_url', Storage::disk('public')->url($errorPath));
        }

        $cccdValues = collect($parsedRows)
            ->pluck('data.so_cccd')
            ->filter()
            ->unique()
            ->values();

        $existingCccd = Driver::query()
            ->whereIn('cccd_no', $cccdValues)
            ->pluck('cccd_no')
            ->map(fn (?string $value) => Str::of((string) $value)->trim()->toString())
            ->all();

        foreach ($parsedRows as $parsed) {
            $rowIndex = $parsed['row_index'];
            $rowNumber = $parsed['row_number'];
            $cccd = $parsed['data']['so_cccd'];
            if ($cccd !== '' && in_array($cccd, $existingCccd, true)) {
                $this->addError(
                    $displayErrors,
                    $rowErrors,
                    $rowIndex,
                    $rowNumber,
                    'so_cccd',
                    'Đã tồn tại trong hệ thống'
                );
            }
        }

        if ($displayErrors !== []) {
            $errorPath = $this->storeErrorFile($rows, $rowErrors, 'drivers');

            return back()
                ->with('import_errors', $displayErrors)
                ->with('error_file_url', Storage::disk('public')->url($errorPath));
        }

        $insertData = collect($parsedRows)->map(function (array $parsed) {
            return [
                'name' => $parsed['data']['ho_ten'],
                'phone' => $parsed['data']['so_dien_thoai'] ?: null,
                'cccd_no' => $parsed['data']['so_cccd'] ?: null,
            ];
        })->values();

        DB::transaction(function () use ($insertData) {
            foreach ($insertData as $payload) {
                Driver::create($payload);
            }
        });

        return redirect()
            ->route('drivers.index')
            ->with('success', 'Import thành công: ' . $insertData->count() . ' dòng');
    }

    private function normalizeHeaderRow(array $row): array
    {
        return array_map(function ($value) {
            return Str::of((string) $value)->trim()->lower()->toString();
        }, $row);
    }

    private function normalizeDataRow(array $row): array
    {
        return array_map(function ($value) {
            return Str::of((string) $value)->trim()->toString();
        }, $row);
    }

    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }

    private function addError(
        array &$displayErrors,
        array &$rowErrors,
        int $rowIndex,
        int $rowNumber,
        string $column,
        string $message
    ): void {
        $displayErrors[] = "Dòng {$rowNumber} - Cột {$column}: {$message}";
        $rowErrors[$rowIndex][] = "Cột {$column}: {$message}";
    }

    private function storeErrorFile(array $rows, array $rowErrors, string $prefix): string
    {
        $exportRows = [];
        foreach ($rows as $index => $row) {
            $rowValues = array_values($row);
            $rowValues[] = $index === 0 ? 'Lỗi' : implode('; ', $rowErrors[$index] ?? []);
            $exportRows[] = $rowValues;
        }

        $path = 'import-errors/' . $prefix . '-' . now()->format('YmdHis') . '.xlsx';
        Excel::store(new ArrayExport($exportRows), $path, 'public');

        return $path;
    }
}
