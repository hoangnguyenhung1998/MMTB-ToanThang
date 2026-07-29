<?php

namespace App\Http\Controllers;

use App\Exports\MachinesExport;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineEvent;
use App\Services\MachineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MachineBatchController extends Controller
{
    public function __construct(private readonly MachineService $machineService)
    {
    }

    public function handover(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'machine_ids' => ['required', 'array', 'min:1'],
            'machine_ids.*' => ['integer', 'exists:machines,id'],
            'project_id' => ['required', 'exists:projects,id'],
            'command_center_id' => ['required', 'exists:command_centers,id'],
            'time_in' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $machines = Machine::query()
            ->whereIn('id', $validated['machine_ids'])
            ->get(['id', 'asset_code', 'status']);

        $invalidStatusMachines = $machines->filter(
            fn (Machine $machine) => !in_array($machine->status, ['WAIT_HANDOVER', 'RETURNED'], true)
        );

        if ($invalidStatusMachines->isNotEmpty()) {
            return back()->withErrors([
                'error' => 'Bàn giao thất bại. Chỉ bàn giao máy WAIT_HANDOVER/RETURNED. Máy lỗi: ' .
                    $invalidStatusMachines->pluck('asset_code')->implode(', '),
            ]);
        }

        $machineIds = $machines->pluck('id');
        $hasOpenAssignment = MachineAssignment::query()
            ->whereIn('machine_id', $machineIds)
            ->whereNull('time_out')
            ->exists();

        if ($hasOpenAssignment) {
            return back()->withErrors([
                'error' => 'Bàn giao thất bại. Có máy đang có assignment mở, vui lòng kiểm tra lại.',
            ]);
        }

        DB::transaction(function () use ($machines, $validated) {
            foreach ($machines as $machine) {
                MachineAssignment::create([
                    'machine_id' => $machine->id,
                    'project_id' => (int) $validated['project_id'],
                    'command_center_id' => (int) $validated['command_center_id'],
                    'time_in' => Carbon::parse($validated['time_in']),
                    'time_out' => null,
                    'proof_file_path' => null,
                ]);

                MachineEvent::create([
                    'machine_id' => $machine->id,
                    'project_id' => (int) $validated['project_id'],
                    'type' => 'HANDOVER',
                    'occurred_at' => Carbon::parse($validated['time_in']),
                    'proof_file_path' => null,
                    'note' => $validated['note'] ?? null,
                    'to_project_id' => (int) $validated['project_id'],
                    'to_command_center_id' => (int) $validated['command_center_id'],
                    'missing_proof' => 1,
                ]);

                $machine->update(['status' => 'HANDED_OVER']);
            }
        });

        return back()->with('success', 'Đã bàn giao ' . $machines->count() . ' máy (không biên bản).');
    }

    public function activate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'machine_ids' => ['required', 'array', 'min:1'],
            'machine_ids.*' => ['integer', 'exists:machines,id'],
            'activated_at' => ['required', 'date'],
        ]);

        $machines = Machine::query()
            ->whereIn('id', $validated['machine_ids'])
            ->get(['id', 'asset_code', 'status']);

        $invalidMachines = $machines->filter(fn (Machine $machine) => $machine->status !== 'HANDED_OVER');

        if ($invalidMachines->isNotEmpty()) {
            $assetCodes = $invalidMachines->pluck('asset_code')->implode(', ');
            return back()->withErrors([
                'error' => 'Kích hoạt thất bại. Các máy không ở trạng thái HANDED_OVER: ' . $assetCodes,
            ]);
        }

        DB::transaction(function () use ($machines, $validated) {
            foreach ($machines as $machine) {
                $this->machineService->activateMachine($machine->id, $validated['activated_at']);
            }
        });

        return back()->with('success', 'Đã kích hoạt ' . $machines->count() . ' máy.');
    }

    public function exportSelected(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'machine_ids' => ['required', 'array', 'min:1'],
            'machine_ids.*' => ['integer', 'exists:machines,id'],
        ]);

        $machines = Machine::query()
            ->whereIn('id', $validated['machine_ids'])
            ->with([
                'assignments' => function ($query) {
                    $query->whereNull('time_out')->with(['project', 'commandCenter']);
                },
                'driverHistories' => function ($query) {
                    $query->with('driver:id,name,phone,cccd_no')->orderByDesc('started_at');
                },
            ])
            ->orderBy('asset_code')
            ->get();

        return Excel::download(
            new MachinesExport($machines),
            'danh-sach-may-da-chon-' . now()->format('YmdHis') . '.xlsx'
        );
    }

    public function delete(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'machine_ids' => ['required', 'array', 'min:1'],
            'machine_ids.*' => ['integer', 'exists:machines,id'],
            'delete_confirm' => ['required', 'in:XOA'],
        ]);

        $machines = Machine::query()
            ->whereIn('id', $validated['machine_ids'])
            ->get();

        $invalidMachines = $machines->filter(
            fn (Machine $machine) => !in_array($machine->status, ['WAIT_HANDOVER', 'RETURNED'], true)
        );

        if ($invalidMachines->isNotEmpty()) {
            $assetCodes = $invalidMachines->pluck('asset_code')->implode(', ');
            return back()->withErrors([
                'error' => 'Xóa thất bại. Chỉ được xóa máy WAIT_HANDOVER/RETURNED. Các máy lỗi: ' . $assetCodes,
            ]);
        }

        $assetCodes = $machines->pluck('asset_code')->all();

        DB::transaction(function () use ($machines) {
            foreach ($machines as $machine) {
                $machine->documents()->delete();
                $machine->driverHistories()->delete();
                $machine->assignments()->delete();
                $machine->events()->delete();
                $machine->delete();
            }
        });

        foreach ($assetCodes as $assetCode) {
            Storage::disk('public')->deleteDirectory('documents/machines/' . $assetCode);
        }

        return back()->with('success', 'Đã xóa ' . count($assetCodes) . ' máy.');
    }
}
