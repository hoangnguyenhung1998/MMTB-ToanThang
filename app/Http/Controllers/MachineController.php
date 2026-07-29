<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Exports\MachinesExport;
use App\Models\CommandCenter;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineDocument;
use App\Models\MachineEvent;
use App\Models\Project;
use App\Services\MachineService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MachineController extends Controller
{
    public function __construct(private readonly MachineService $machineService)
    {
    }

    public function index(Request $request): View
    {
        $machines = $this->buildIndexQuery($request)
            ->orderBy('asset_code')
            ->paginate(20)
            ->withQueryString();

        return view('machines.index', [
            'machines' => $machines,
            'search' => $request->string('q')->toString(),
            'filters' => [
                'company' => $request->string('company')->toString(),
                'status' => $request->string('status')->toString(),
                'project_id' => $request->input('project_id'),
                'command_center_id' => $request->input('command_center_id'),
                'return_app_status' => $request->input('return_app_status'),
            ],
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'commandCenters' => CommandCenter::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $machines = $this->buildIndexQuery($request)
            ->with([
                'driverHistories' => function ($query) {
                    $query->with('driver:id,name,phone,cccd_no')
                        ->orderByDesc('started_at');
                },
            ])
            ->orderBy('asset_code')
            ->get();

        return Excel::download(
            new MachinesExport($machines),
            'danh-sach-may-' . now()->format('YmdHis') . '.xlsx'
        );
    }

    public function create(): View
    {
        return view('machines.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'asset_code' => ['required', 'string', 'max:255'],
            'company' => ['required', 'in:VINCONS,VINALPHA'],
            'chassis_no' => ['required', 'string', 'max:255'],
            'engine_no' => ['nullable', 'string', 'max:255'],
            'plate_no' => ['nullable', 'string', 'max:255'],
            'machine_type' => ['nullable', 'string', 'max:255'],
            'manufacture_year' => ['nullable', 'integer', 'min:1900', 'max:' . (now()->year + 1)],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['file', 'max:204800'],
            'document_type' => ['nullable', 'string', 'max:255'],
        ]);

        $machineData = $validated;
        unset($machineData['documents'], $machineData['document_type']);

        try {
            $machine = $this->machineService->createMachine($machineData);
        } catch (BusinessRuleException $exception) {
            return back()
                ->withErrors(['error' => $exception->getMessage()])
                ->withInput();
        }

        if ($request->hasFile('documents') && $request->filled('document_type')) {
            foreach ($request->file('documents', []) as $file) {
                $path = $file->store("documents/machines/{$machine->asset_code}", 'public');
                MachineDocument::create([
                    'machine_id' => $machine->id,
                    'doc_type' => $validated['document_type'],
                    'file_path' => $path,
                ]);
            }
        }

        return redirect()
            ->route('machines.show', $machine)
            ->with('success', 'Tạo máy thành công.');
    }

    public function show(Machine $machine): View
    {
        $currentInfo = $this->machineService->getCurrentInfo($machine->id);
        $history = $this->machineService->getHistory($machine->id);

        $proofEvents = MachineEvent::with(['fromProject', 'toProject', 'fromCommandCenter', 'toCommandCenter'])
            ->where('machine_id', $machine->id)
            ->whereIn('type', ['HANDOVER', 'TRANSFER', 'RETURN'])
            ->orderByDesc('occurred_at')
            ->get();

        return view('machines.show', [
            'machine' => $machine,
            'currentInfo' => $currentInfo,
            'assignments' => $history['assignments'],
            'driverHistory' => $history['driver_history'],
            'events' => $history['events'],
            'proofEvents' => $proofEvents,
        ]);
    }

    public function edit(Machine $machine): View
    {
        return view('machines.edit', [
            'machine' => $machine,
        ]);
    }

    public function update(Request $request, Machine $machine): RedirectResponse
    {
        $validated = $request->validate([
            'asset_code' => ['required', 'string', 'max:255'],
            'company' => ['required', 'in:VINCONS,VINALPHA'],
            'chassis_no' => ['required', 'string', 'max:255'],
            'engine_no' => ['nullable', 'string', 'max:255'],
            'plate_no' => ['nullable', 'string', 'max:255'],
            'machine_type' => ['nullable', 'string', 'max:255'],
            'manufacture_year' => ['nullable', 'integer', 'min:1900', 'max:' . (now()->year + 1)],
            'status' => ['required', 'in:WAIT_HANDOVER,HANDED_OVER,ACTIVE,RETURNED'],
        ]);

        $machine->update($validated);

        return redirect()
            ->route('machines.show', $machine)
            ->with('success', 'Đã cập nhật máy.');
    }

    public function destroy(Machine $machine): RedirectResponse
    {
        if (!in_array($machine->status, ['WAIT_HANDOVER', 'RETURNED'], true)) {
            return back()->withErrors([
                'error' => 'Chỉ được xóa máy ở trạng thái WAIT_HANDOVER hoặc RETURNED.',
            ]);
        }

        $assetCode = $machine->asset_code;

        DB::transaction(function () use ($machine) {
            $machine->documents()->delete();
            $machine->driverHistories()->delete();
            $machine->assignments()->delete();
            $machine->events()->delete();
            $machine->delete();
        });

        Storage::disk('public')->deleteDirectory("documents/machines/{$assetCode}");

        return redirect()
            ->route('machines.index')
            ->with('success', 'Đã xóa máy và toàn bộ hồ sơ liên quan.');
    }

    public function changeDriverForm(Machine $machine): View
    {
        $drivers = Driver::orderBy('name')->get();

        return view('machines.change-driver', [
            'machine' => $machine,
            'drivers' => $drivers,
        ]);
    }

    public function changeDriverSubmit(Request $request, Machine $machine): RedirectResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'exists:drivers,id'],
            // Thời gian bắt đầu được tự động lấy theo thời điểm xác nhận.
        ]);

        try {
            $this->machineService->assignDriver(
                $machine->id,
                (int) $validated['driver_id'],
                now()
            );
        } catch (BusinessRuleException $exception) {
            return back()
                ->withErrors(['error' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('machines.show', $machine)
            ->with('success', 'Đã đổi tài xế.');
    }

    // private function buildIndexQuery(Request $request): Builder
    // {
    //     $query = Machine::with([
    //         'currentDriver',
    //         'assignments' => function ($assignmentQuery) {
    //             $assignmentQuery->whereNull('time_out')->with(['project', 'commandCenter']);
    //         },
    //     ])->withExists([
    //         'events as has_missing_handover_proof' => function ($eventQuery) {
    //             $eventQuery->where('type', 'HANDOVER')
    //                 ->where(function ($inner) {
    //                     $inner->where('missing_proof', true)
    //                         ->orWhereNull('proof_file_path');
    //                 });
    //         },
    //     ]);

    //     if ($request->filled('q')) {
    //         $query->where('asset_code', 'like', '%' . $request->string('q')->toString() . '%');
    //     }

    //     if ($request->filled('company')) {
    //         $query->where('company', $request->string('company')->toString());
    //     }

    //     if ($request->filled('status')) {
    //         $query->where('status', $request->string('status')->toString());
    //     }

    //     if ($request->filled('project_id')) {
    //         $projectId = (int) $request->input('project_id');
    //         $query->whereHas('assignments', function ($assignmentQuery) use ($projectId) {
    //             $assignmentQuery->whereNull('time_out')->where('project_id', $projectId);
    //         });
    //     }

    //     if ($request->filled('command_center_id')) {
    //         $commandCenterId = (int) $request->input('command_center_id');
    //         $query->whereHas('assignments', function ($assignmentQuery) use ($commandCenterId) {
    //             $assignmentQuery->whereNull('time_out')->where('command_center_id', $commandCenterId);
    //         });
    //     }

    //     return $query;
    // }

    private function buildIndexQuery(Request $request): Builder
    {
        $query = Machine::with([
            'currentDriver',
            'currentAssignment.project',
            'currentAssignment.commandCenter',
            'latestAssignment',
            'documents',
        ])->withExists([
            'events as has_missing_handover_proof' => function ($eventQuery) {
                $eventQuery->where('type', 'HANDOVER')
                    ->where(function ($inner) {
                        $inner->where('missing_proof', true)
                            ->orWhereNull('proof_file_path');
                    });
            },
        ]);

        if ($request->filled('q')) {
            $query->where('asset_code', 'like', '%' . $request->string('q')->toString() . '%');
        }

        if ($request->filled('company')) {
            $query->where('company', $request->string('company')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('project_id')) {
            $projectId = (int) $request->input('project_id');
            $query->whereHas('currentAssignment', function ($assignmentQuery) use ($projectId) {
                $assignmentQuery->where('project_id', $projectId);
            });
        }

        if ($request->filled('command_center_id')) {
            $commandCenterId = (int) $request->input('command_center_id');
            $query->whereHas('currentAssignment', function ($assignmentQuery) use ($commandCenterId) {
                $assignmentQuery->where('command_center_id', $commandCenterId);
            });
        }

        if ($request->input('return_app_status') === 'pending') {
            $query->where('status', 'RETURNED')
                ->where('returned_to_app', false);
        }

        return $query;
    }
}
