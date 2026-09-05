<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\Machine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MachineImportController extends Controller
{
    private const HEADER = [
        'asset_code',
        'chassis_no',
        'engine_no',
        'plate_no',
        'machine_type',
        'manufacture_year',
        'company',
    ];

    public function form(): View
    {
        return view('machines.import');
    }

    public function template(): BinaryFileResponse
    {
        $rows = [
            self::HEADER,
            ['MAY-001', 'CHASSIS-001', 'ENGINE-001', '29A-12345', 'Excavator', '2021', 'VINCONS'],
            ['MAY-002', 'CHASSIS-002', '', '', 'Loader', '2020', 'VINALPHA'],
        ];

        return Excel::download(new ArrayExport($rows), 'mau-import-may.xlsx');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'company' => ['nullable', new \App\Rules\AvailableCompany()],
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

        $allowedCompanies = \App\Models\Company::where('is_active', true)->pluck('code')->all();
        $seenAssetCodes = [];
        $seenChassis = [];
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
            if (trim((string) $mapped['company']) === '' && $request->filled('company')) {
                $mapped['company'] = $request->string('company')->toString();
            }

            $rowNumber = $index + 1;
            $assetCode = $mapped['asset_code'];
            $chassisNo = $mapped['chassis_no'];
            $company = $mapped['company'];

            if ($assetCode === '') {
                $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'asset_code', 'Bắt buộc');
            } else {
                $assetKey = Str::upper($assetCode);
                if (isset($seenAssetCodes[$assetKey])) {
                    $this->addError(
                        $displayErrors,
                        $rowErrors,
                        $index,
                        $rowNumber,
                        'asset_code',
                        'Trùng trong file'
                    );
                }
                $seenAssetCodes[$assetKey] = true;
            }

            if ($chassisNo === '') {
                $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'chassis_no', 'Bắt buộc');
            } else {
                $chassisKey = Str::upper($chassisNo);
                if (isset($seenChassis[$chassisKey])) {
                    $this->addError(
                        $displayErrors,
                        $rowErrors,
                        $index,
                        $rowNumber,
                        'chassis_no',
                        'Trùng trong file'
                    );
                }
                $seenChassis[$chassisKey] = true;
            }

            if ($company === '') {
                $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'company', 'Bắt buộc');
            } else {
                $normalizedCompany = Str::upper($company);
                if (!in_array($normalizedCompany, $allowedCompanies, true)) {
                    $this->addError(
                        $displayErrors,
                        $rowErrors,
                        $index,
                        $rowNumber,
                        'company',
                        'Công ty chưa tồn tại hoặc đã ngừng sử dụng. Kiểm tra danh mục công ty.'
                    );
                }
                $mapped['company'] = $normalizedCompany;
            }

            if (($mapped['manufacture_year'] ?? '') !== '') {
                if (!preg_match('/^\d{4}$/', $mapped['manufacture_year'])) {
                    $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'manufacture_year', 'Phải là năm gồm 4 chữ số, ví dụ 2021');
                } else {
                    $year = (int) $mapped['manufacture_year'];
                    $maxYear = now()->year + 1;
                    if ($year < 1900 || $year > $maxYear) {
                        $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'manufacture_year', "Chỉ nhận từ 1900 đến {$maxYear}");
                    }
                    $mapped['manufacture_year'] = $year;
                }
            } else {
                $mapped['manufacture_year'] = null;
            }

            $parsedRows[] = [
                'row_index' => $index,
                'row_number' => $rowNumber,
                'data' => $mapped,
            ];
        }

        if ($parsedRows === []) {
            $errorPath = $this->storeErrorFile($rows, $rowErrors, 'machines');

            return back()
                ->with('import_errors', ['File không có dữ liệu hợp lệ.'])
                ->with('error_file_url', Storage::disk('public')->url($errorPath));
        }

        $assetCodes = collect($parsedRows)
            ->pluck('data.asset_code')
            ->filter()
            ->unique()
            ->values();
        $chassisNos = collect($parsedRows)
            ->pluck('data.chassis_no')
            ->filter()
            ->unique()
            ->values();

        $existingAssets = Machine::query()
            ->whereIn('asset_code', $assetCodes)
            ->pluck('asset_code')
            ->map(fn (string $value) => Str::upper($value))
            ->all();
        $existingChassis = Machine::query()
            ->whereIn('chassis_no', $chassisNos)
            ->pluck('chassis_no')
            ->map(fn (string $value) => Str::upper($value))
            ->all();

        foreach ($parsedRows as $parsed) {
            $rowIndex = $parsed['row_index'];
            $rowNumber = $parsed['row_number'];
            $assetKey = Str::upper($parsed['data']['asset_code']);
            if ($assetKey !== '' && in_array($assetKey, $existingAssets, true)) {
                $this->addError(
                    $displayErrors,
                    $rowErrors,
                    $rowIndex,
                    $rowNumber,
                    'asset_code',
                    'Đã tồn tại trong hệ thống'
                );
            }

            $chassisKey = Str::upper($parsed['data']['chassis_no']);
            if ($chassisKey !== '' && in_array($chassisKey, $existingChassis, true)) {
                $this->addError(
                    $displayErrors,
                    $rowErrors,
                    $rowIndex,
                    $rowNumber,
                    'chassis_no',
                    'Đã tồn tại trong hệ thống'
                );
            }
        }

        if ($displayErrors !== []) {
            $errorPath = $this->storeErrorFile($rows, $rowErrors, 'machines');

            return back()
                ->with('import_errors', $displayErrors)
                ->with('error_file_url', Storage::disk('public')->url($errorPath));
        }

        $insertData = collect($parsedRows)->map(function (array $parsed) {
            return [
                'asset_code' => $parsed['data']['asset_code'],
                'chassis_no' => $parsed['data']['chassis_no'],
                'engine_no' => $parsed['data']['engine_no'] ?: null,
                'plate_no' => $parsed['data']['plate_no'] ?: null,
                'machine_type' => $parsed['data']['machine_type'] ?: null,
                'manufacture_year' => $parsed['data']['manufacture_year'] ?: null,
                'company' => $parsed['data']['company'],
                'status' => 'WAIT_HANDOVER',
            ];
        })->values();

        DB::transaction(function () use ($insertData) {
            foreach ($insertData as $payload) {
                Machine::create($payload);
            }
        });

        return redirect()
            ->route('machines.index')
            ->with('success', 'Import thành công: ' . $insertData->count() . ' dòng');
    }



public function manufactureYearTemplate(): BinaryFileResponse
{
    $rows = [
        ['asset_code', 'manufacture_year'],
        ['SGC-T-3C0464', '2021'],
        ['SGC-T-3C0465', '2022'],
    ];

    return Excel::download(new ArrayExport($rows), 'mau-cap-nhat-nam-san-xuat-may.xlsx');
}

public function importManufactureYears(Request $request): RedirectResponse
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
    $headerMap = $this->mapManufactureYearHeader($rows[0] ?? []);

    if (!isset($headerMap['asset_code'])) {
        $this->addError($displayErrors, $rowErrors, 0, 1, 'asset_code', 'Thiếu cột mã máy. Chấp nhận: asset_code, mã máy, ma may');
    }
    if (!isset($headerMap['manufacture_year'])) {
        $this->addError($displayErrors, $rowErrors, 0, 1, 'manufacture_year', 'Thiếu cột năm sản xuất. Chấp nhận: manufacture_year, năm sản xuất, nam sx');
    }

    if ($displayErrors !== []) {
        $errorPath = $this->storeErrorFile($rows, $rowErrors, 'machines-manufacture-years');

        return back()
            ->with('import_errors', $displayErrors)
            ->with('error_file_url', Storage::disk('public')->url($errorPath));
    }

    $parsedRows = [];
    $seenAssetCodes = [];
    $currentYear = now()->year + 1;

    foreach ($rows as $index => $row) {
        if ($index === 0) {
            continue;
        }

        $normalized = $this->normalizeDataRow($row);
        $assetCode = $normalized[$headerMap['asset_code']] ?? '';
        $yearValue = $normalized[$headerMap['manufacture_year']] ?? '';

        if ($assetCode === '' && $yearValue === '') {
            continue;
        }

        $rowNumber = $index + 1;

        if ($assetCode === '') {
            $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'asset_code', 'Bắt buộc');
        } else {
            $assetKey = Str::upper($assetCode);
            if (isset($seenAssetCodes[$assetKey])) {
                $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'asset_code', 'Trùng trong file');
            }
            $seenAssetCodes[$assetKey] = true;
        }

        $manufactureYear = null;
        if ($yearValue === '') {
            $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'manufacture_year', 'Bắt buộc');
        } elseif (!preg_match('/^\d{4}$/', $yearValue)) {
            $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'manufacture_year', 'Phải là năm gồm 4 chữ số, ví dụ 2021');
        } else {
            $manufactureYear = (int) $yearValue;
            if ($manufactureYear < 1900 || $manufactureYear > $currentYear) {
                $this->addError($displayErrors, $rowErrors, $index, $rowNumber, 'manufacture_year', "Chỉ nhận từ 1900 đến {$currentYear}");
            }
        }

        $parsedRows[] = [
            'row_index' => $index,
            'row_number' => $rowNumber,
            'asset_code' => $assetCode,
            'manufacture_year' => $manufactureYear,
        ];
    }

    if ($parsedRows === []) {
        $errorPath = $this->storeErrorFile($rows, $rowErrors, 'machines-manufacture-years');

        return back()
            ->with('import_errors', ['File không có dữ liệu hợp lệ.'])
            ->with('error_file_url', Storage::disk('public')->url($errorPath));
    }

    $assetCodes = collect($parsedRows)->pluck('asset_code')->filter()->unique()->values();
    $existingMachines = Machine::query()
        ->whereIn('asset_code', $assetCodes)
        ->get(['id', 'asset_code'])
        ->keyBy(fn (Machine $machine) => Str::upper($machine->asset_code));

    foreach ($parsedRows as $parsed) {
        $assetKey = Str::upper($parsed['asset_code']);
        if ($assetKey !== '' && !$existingMachines->has($assetKey)) {
            $this->addError(
                $displayErrors,
                $rowErrors,
                $parsed['row_index'],
                $parsed['row_number'],
                'asset_code',
                'Không tìm thấy mã máy trong hệ thống'
            );
        }
    }

    if ($displayErrors !== []) {
        $errorPath = $this->storeErrorFile($rows, $rowErrors, 'machines-manufacture-years');

        return back()
            ->with('import_errors', $displayErrors)
            ->with('error_file_url', Storage::disk('public')->url($errorPath));
    }

    DB::transaction(function () use ($parsedRows, $existingMachines) {
        foreach ($parsedRows as $parsed) {
            $machine = $existingMachines->get(Str::upper($parsed['asset_code']));
            $machine->update([
                'manufacture_year' => $parsed['manufacture_year'],
            ]);
        }
    });

    return redirect()
        ->route('machines.index')
        ->with('success', 'Đã cập nhật năm sản xuất cho ' . count($parsedRows) . ' máy.');
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



private function mapManufactureYearHeader(array $row): array
{
    $aliases = [
        'asset_code' => ['asset_code', 'ma_may', 'ma_thiet_bi', 'mã_máy', 'mã_thiết_bị'],
        'manufacture_year' => ['manufacture_year', 'nam_san_xuat', 'nam_sx', 'năm_sản_xuất', 'năm_sx'],
    ];

    $map = [];
    foreach ($row as $index => $value) {
        $key = $this->normalizeHeaderKey((string) $value);
        foreach ($aliases as $field => $fieldAliases) {
            if (in_array($key, $fieldAliases, true)) {
                $map[$field] = $index;
            }
        }
    }

    return $map;
}

private function normalizeHeaderKey(string $value): string
{
    return Str::of($value)
        ->trim()
        ->lower()
        ->ascii()
        ->replaceMatches('/[^a-z0-9]+/', '_')
        ->trim('_')
        ->toString();
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
