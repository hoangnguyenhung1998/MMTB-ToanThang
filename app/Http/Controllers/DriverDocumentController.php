<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\DriverDocument;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DriverDocumentController extends Controller
{
    private const MAX_UPLOAD_KB = 204800; // 200MB

    public function index(Driver $driver): View
    {
        $documents = $driver->documents()->orderByDesc('created_at')->paginate(20)->withQueryString();

        return view('driver-documents.index', [
            'driver' => $driver,
            'documents' => $documents,
        ]);
    }

    public function create(Driver $driver): View
    {
        return view('driver-documents.create', [
            'driver' => $driver,
            'validityOptions' => $this->validityOptions(),
        ]);
    }

    public function store(Request $request, Driver $driver): RedirectResponse
    {
        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'max:255'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:' . self::MAX_UPLOAD_KB],
            'issued_date' => ['nullable', 'string', 'max:20'],
            'validity_period' => ['nullable', 'in:3_months,6_months,1_year,2_years,permanent'],
            'note' => ['nullable', 'string'],
        ]);

        $issuedDate = $this->parseVietnameseDate($validated['issued_date'] ?? null, 'Ngày cấp');
        $validityPeriod = $validated['validity_period'] ?? null;
        $expiryDate = $this->calculateExpiryDate($issuedDate, $validityPeriod);
        $folder = 'documents/drivers/' . Str::slug($driver->name) . '-' . $driver->id;
        $files = $request->file('files', []);

        if (empty($files)) {
            DriverDocument::create([
                'driver_id' => $driver->id,
                'doc_type' => $validated['doc_type'],
                'file_path' => null,
                'issued_date' => $issuedDate,
                'expiry_date' => $expiryDate,
                'validity_period' => $validityPeriod,
                'note' => $validated['note'] ?? null,
            ]);
        }

        foreach ($files as $file) {
            $path = $file->store($folder, 'public');

            DriverDocument::create([
                'driver_id' => $driver->id,
                'doc_type' => $validated['doc_type'],
                'file_path' => $path,
                'issued_date' => $issuedDate,
                'expiry_date' => $expiryDate,
                'validity_period' => $validityPeriod,
                'note' => $validated['note'] ?? null,
            ]);
        }

        return redirect()
            ->route('drivers.show', $driver)
            ->with('success', 'Đã thêm giấy tờ tài xế.');
    }

    public function edit(Driver $driver, DriverDocument $document): View
    {
        return view('driver-documents.edit', [
            'driver' => $driver,
            'document' => $document,
            'validityOptions' => $this->validityOptions(),
        ]);
    }

    public function update(Request $request, Driver $driver, DriverDocument $document): RedirectResponse
    {
        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'max:255'],
            'issued_date' => ['nullable', 'string', 'max:20'],
            'validity_period' => ['nullable', 'in:3_months,6_months,1_year,2_years,permanent'],
            'note' => ['nullable', 'string', 'max:1000'],
            'file' => ['nullable', 'file', 'max:' . self::MAX_UPLOAD_KB],
        ]);

        $issuedDate = $this->parseVietnameseDate($validated['issued_date'] ?? null, 'Ngày cấp');
        $validityPeriod = $validated['validity_period'] ?? null;
        $expiryDate = $this->calculateExpiryDate($issuedDate, $validityPeriod);

        $updateData = [
            'doc_type' => $validated['doc_type'],
            'issued_date' => $issuedDate,
            'expiry_date' => $expiryDate,
            'validity_period' => $validityPeriod,
            'note' => $validated['note'] ?? null,
        ];

        if ($request->hasFile('file')) {
            if (!empty($document->file_path) && Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }

            $folder = 'documents/drivers/' . Str::slug($driver->name) . '-' . $driver->id;
            $updateData['file_path'] = $request->file('file')->store($folder, 'public');
        }

        $document->update($updateData);

        return redirect()
            ->route('drivers.show', $driver)
            ->with('success', 'Đã cập nhật giấy tờ tài xế.');
    }

    public function destroy(Driver $driver, DriverDocument $document): RedirectResponse
    {
        if (!empty($document->file_path) && Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }
        $document->delete();

        return redirect()
            ->route('drivers.show', $driver)
            ->with('success', 'Đã xoá giấy tờ tài xế.');
    }

    private function validityOptions(): array
    {
        return [
            '3_months' => '3 tháng',
            '6_months' => '6 tháng',
            '1_year' => '1 năm',
            '2_years' => '2 năm',
            'permanent' => 'Vĩnh viễn',
        ];
    }

    private function parseVietnameseDate(?string $value, string $fieldLabel): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable) {
                // Try the next supported format.
            }
        }

        throw ValidationException::withMessages([
            'issued_date' => $fieldLabel . ' phải đúng định dạng ngày/tháng/năm, ví dụ 05/11/2025.',
        ]);
    }

    private function calculateExpiryDate(?string $issuedDate, ?string $validityPeriod): ?string
    {
        if (!$validityPeriod || $validityPeriod === 'permanent') {
            return null;
        }

        if (!$issuedDate) {
            return null;
        }

        $date = Carbon::parse($issuedDate);

        return match ($validityPeriod) {
            '3_months' => $date->copy()->addMonthsNoOverflow(3)->format('Y-m-d'),
            '6_months' => $date->copy()->addMonthsNoOverflow(6)->format('Y-m-d'),
            '1_year' => $date->copy()->addYearNoOverflow()->format('Y-m-d'),
            '2_years' => $date->copy()->addYearsNoOverflow(2)->format('Y-m-d'),
            default => null,
        };
    }
}
