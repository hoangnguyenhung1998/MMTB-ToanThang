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
        $roster = app(\App\Services\DossierZaloRoster::class);
        $identities = $roster->identities()->filter(fn ($ids) => $ids->count() === 1);
        $senderNames = $roster->names($identities->keys());
        $senders = $identities->keys()->groupBy(fn ($sender) => $identities[$sender]->first());
        $machines = $roster->snapshot()['rows']->pluck('machine_id', 'driver_id');
        $codes = \App\Models\Machine::whereIn('id', $machines->values())->pluck('asset_code', 'id');
        $legal = collect();
        if ($request->user()->can('manage-dossiers')) {
            $legal = \App\Models\DossierRecord::whereNotNull('driver_id')->whereHas('type', fn ($q) => $q->where('ai_key', 'identity'))->distinct()->pluck('driver_id')
                ->mapWithKeys(fn ($id) => [$id => app(\App\Services\Dossiers\Ai\DossierComparison::class)->identity($id)]);
        }
        match ($request->query('zalo')) {
            'linked' => $query->whereIn('id', $senders->keys()),
            'unlinked' => $query->whereNotIn('id', $senders->keys()),
            default => null,
        };

        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $extra = $senders->filter(fn ($ids) => $ids->contains(fn ($sender) => str_contains(mb_strtolower($sender.' '.($senderNames[$sender] ?? '')), mb_strtolower($search))))->keys()
                ->concat($machines->filter(fn ($machine) => str_contains(mb_strtolower($codes[$machine] ?? ''), mb_strtolower($search)))->keys())
                ->concat($legal->filter(fn ($identity) => str_contains(mb_strtolower(($identity['person_name'] ?? '').' '.($identity['identity'] ?? '')), mb_strtolower($search)))->keys());
            $query->where(function ($builder) use ($search, $extra) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('cccd_no', 'like', "%{$search}%")->orWhereIn('id', $extra);
            });
        }

        $drivers = $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();

        return view('drivers.index', [
            'drivers' => $drivers,
            'search' => $request->string('q')->toString(),
            'senders' => $senders, 'senderNames' => $senderNames, 'legal' => $legal, 'machines' => $machines, 'codes' => $codes,
        ]);
    }

    public function updatePhone(Request $request, Driver $driver): RedirectResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:255']]);
        $driver->update(['phone' => $data['phone'] ?? null]);

        return back()->with('success', 'Đã lưu số điện thoại.');
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
        if (\App\Models\DossierRecord::where('driver_id', $driver->id)->exists() || \App\Models\DossierSenderProfile::where('driver_id', $driver->id)->exists()) {
            return back()->withErrors(['error' => 'Tài xế có hồ sơ điện tử / liên kết Zalo cần giữ lịch sử; không thể xóa.']);
        }
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
