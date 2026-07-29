<?php

namespace App\Http\Controllers;

use App\Exports\DriversExport;
use App\Models\Driver;
use App\Models\MachineDriverHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DriverController extends Controller
{
    public function index(Request $request): View
    {
        $query = Driver::query();

        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('cccd_no', 'like', "%{$search}%");
            });
        }

        $drivers = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('drivers.index', [
            'drivers' => $drivers,
            'search' => $request->string('q')->toString(),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $query = Driver::query();

        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('cccd_no', 'like', "%{$search}%");
            });
        }

        $drivers = $query->orderBy('name')->get();

        return Excel::download(
            new DriversExport($drivers),
            'danh-sach-tai-xe-' . now()->format('YmdHis') . '.xlsx'
        );
    }

    public function create(Request $request): View
    {
        return view('drivers.create', [
            'redirect' => $request->query('redirect'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'cccd_no' => ['nullable', 'string', 'max:255'],
            'redirect' => ['nullable', 'string'],
        ]);

        $driver = Driver::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'cccd_no' => $validated['cccd_no'] ?? null,
        ]);

        if (!empty($validated['redirect'])) {
            return redirect($validated['redirect'])
                ->with('success', 'Đã tạo tài xế mới.');
        }

        return redirect()
            ->route('drivers.show', $driver)
            ->with('success', 'Đã tạo tài xế mới.');
    }

    public function show(Driver $driver): View
    {
        $documents = $driver->documents()->orderByDesc('created_at')->get();
        $machineHistory = MachineDriverHistory::with('machine')
            ->where('driver_id', $driver->id)
            ->orderByDesc('started_at')
            ->get();

        return view('drivers.show', [
            'driver' => $driver,
            'documents' => $documents,
            'machineHistory' => $machineHistory,
        ]);
    }

    public function edit(Driver $driver): View
    {
        return view('drivers.edit', [
            'driver' => $driver,
        ]);
    }

    public function update(Request $request, Driver $driver): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'cccd_no' => ['nullable', 'string', 'max:255'],
        ]);

        $driver->update($validated);

        return redirect()
            ->route('drivers.show', $driver)
            ->with('success', 'Đã cập nhật tài xế.');
    }

    public function destroy(Driver $driver): RedirectResponse
    {
        $hasOpenShift = MachineDriverHistory::query()
            ->where('driver_id', $driver->id)
            ->whereNull('ended_at')
            ->exists();

        if ($hasOpenShift) {
            return back()->withErrors([
                'error' => 'Không thể xóa tài xế đang có ca vận hành mở.',
            ]);
        }

        $folderPath = 'documents/drivers/' . Str::slug($driver->name) . '-' . $driver->id;

        DB::transaction(function () use ($driver) {
            $driver->documents()->delete();
            $driver->forceDelete();
        });

        Storage::disk('public')->deleteDirectory($folderPath);

        return redirect()
            ->route('drivers.index')
            ->with('success', 'Đã xóa tài xế và toàn bộ hồ sơ liên quan.');
    }
}
