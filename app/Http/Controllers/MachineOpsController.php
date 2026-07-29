<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\CommandCenter;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\Project;
use App\Services\MachineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class MachineOpsController extends Controller
{
    public function __construct(private readonly MachineService $machineService)
    {
    }

    public function handoverForm(Machine $machine): View
    {
        $commandCenters = CommandCenter::orderBy('name')->get();
        $projects = Project::orderBy('name')->get();

        return view('ops.handover', [
            'machine' => $machine,
            'commandCenters' => $commandCenters,
            'projects' => $projects,
        ]);
    }

    public function handoverSubmit(Request $request, Machine $machine): RedirectResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'command_center_id' => ['required', 'exists:command_centers,id'],
            'time_in' => ['required', 'date'],
            'proof_file' => ['required', 'file', 'max:5120'],
        ]);

        $proofFile = $request->file('proof_file');
        $proofFilename = $this->buildProofFileName(
            'HANDOVER',
            $machine,
            ['time_in' => $validated['time_in']],
            $proofFile->getClientOriginalExtension()
        );
        $path = $proofFile->storeAs("documents/machines/{$machine->asset_code}/proofs", $proofFilename, 'public');

        try {
            $this->machineService->handoverToProject(
                $machine->id,
                (int) $validated['project_id'],
                (int) $validated['command_center_id'],
                $validated['time_in'],
                $path
            );
        } catch (BusinessRuleException $exception) {
            return back()
                ->withErrors(['error' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('machines.show', $machine)
            ->with('success', 'Bàn giao máy thành công.');
    }

    public function activateSubmit(Request $request, Machine $machine): RedirectResponse
    {
        $validated = $request->validate([
            'time' => ['required', 'date'],
        ]);

        try {
            $this->machineService->activateMachine($machine->id, $validated['time']);
        } catch (BusinessRuleException $exception) {
            return back()
                ->withErrors(['error' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('machines.show', $machine)
            ->with('success', 'Kích hoạt máy thành công.');
    }

    public function transferForm(Machine $machine): View
    {
        $commandCenters = CommandCenter::orderBy('name')->get();
        $projects = Project::orderBy('name')->get();
        $currentAssignment = MachineAssignment::with(['project', 'commandCenter'])
            ->where('machine_id', $machine->id)
            ->whereNull('time_out')
            ->first();

        return view('ops.transfer', [
            'machine' => $machine,
            'commandCenters' => $commandCenters,
            'projects' => $projects,
            'currentAssignment' => $currentAssignment,
        ]);
    }

    public function transferSubmit(Request $request, Machine $machine): RedirectResponse
    {
        $validated = $request->validate([
            'from_project_id' => ['required', 'exists:projects,id'],
            'from_command_center_id' => ['required', 'exists:command_centers,id'],
            'to_project_id' => ['required', 'exists:projects,id'],
            'to_command_center_id' => ['required', 'exists:command_centers,id'],
            'time_out' => ['required', 'date'],
            'time_in' => ['required', 'date'],
            'proof_file' => ['nullable', 'file', 'max:5120'],
        ]);

        $requiresProof = (int) $validated['to_project_id'] !== (int) $validated['from_project_id'];
        $path = null;

        if ($requiresProof) {
            if (!$request->hasFile('proof_file')) {
                return back()
                    ->withErrors(['error' => 'Bắt buộc có file chứng từ khi đổi dự án.'])
                    ->withInput();
            }

            $proofFile = $request->file('proof_file');
            $proofFilename = $this->buildProofFileName(
                'TRANSFER',
                $machine,
                ['time_out' => $validated['time_out'], 'time_in' => $validated['time_in']],
                $proofFile->getClientOriginalExtension()
            );
            $path = $proofFile->storeAs("documents/machines/{$machine->asset_code}/proofs", $proofFilename, 'public');
        } elseif ($request->hasFile('proof_file')) {
            $proofFile = $request->file('proof_file');
            $proofFilename = $this->buildProofFileName(
                'TRANSFER',
                $machine,
                ['time_out' => $validated['time_out'], 'time_in' => $validated['time_in']],
                $proofFile->getClientOriginalExtension()
            );
            $path = $proofFile->storeAs("documents/machines/{$machine->asset_code}/proofs", $proofFilename, 'public');
        }

        try {
            $this->machineService->transferAssignment(
                $machine->id,
                (int) $validated['from_project_id'],
                (int) $validated['from_command_center_id'],
                (int) $validated['to_project_id'],
                (int) $validated['to_command_center_id'],
                $validated['time_out'],
                $validated['time_in'],
                $path
            );
        } catch (BusinessRuleException $exception) {
            return back()
                ->withErrors(['error' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('machines.show', $machine)
            ->with('success', 'Điều chuyển máy thành công.');
    }

    public function returnForm(Machine $machine): View
    {
        return view('ops.return', [
            'machine' => $machine,
        ]);
    }

    public function returnSubmit(Request $request, Machine $machine): RedirectResponse
    {
        $validated = $request->validate([
            'time_out' => ['required', 'date'],
            'proof_file' => ['required', 'file', 'max:5120'],
            'app_return_confirmed' => ['sometimes', 'boolean'],
        ]);

        $proofFile = $request->file('proof_file');
        $proofFilename = $this->buildProofFileName(
            'RETURN',
            $machine,
            ['time_out' => $validated['time_out']],
            $proofFile->getClientOriginalExtension()
        );
        $path = $proofFile->storeAs("documents/machines/{$machine->asset_code}/proofs", $proofFilename, 'public');

        try {
            $this->machineService->returnToCompany(
                $machine->id,
                $validated['time_out'],
                $path,
                $request->boolean('app_return_confirmed')
            );
        } catch (BusinessRuleException $exception) {
            return back()
                ->withErrors(['error' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('machines.show', $machine)
            ->with('success', 'Đã trả máy về công ty.');
    }


    public function markReturnedToApp(Request $request, Machine $machine): RedirectResponse
    {
        if ($machine->status !== 'RETURNED') {
            return back()->withErrors([
                'error' => 'Chỉ cập nhật đẩy app trả cho máy đã trả.',
            ]);
        }

        $machine->update([
            'returned_to_app' => true,
        ]);

        $machine->events()
            ->where('type', 'RETURN')
            ->latest('occurred_at')
            ->first()
            ?->update(['app_return_confirmed' => true]);

        return back()->with('success', 'Đã xác nhận máy đã đẩy app trả.');
    }

    public function assignDriverForm(Machine $machine): View
    {
        $drivers = Driver::orderBy('name')->get();

        return view('ops.assign-driver', [
            'machine' => $machine,
            'drivers' => $drivers,
            'redirect' => route('ops.assign-driver.form', $machine),
        ]);
    }

    public function assignDriverSubmit(Request $request, Machine $machine): RedirectResponse
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
            ->with('success', 'Gán tài xế thành công.');
    }

    private function buildProofFileName(string $type, Machine $machine, array $times, string $extension): string
    {
        $ext = strtolower($extension ?: 'dat');

        if ($type === 'HANDOVER') {
            $timeIn = Carbon::parse($times['time_in']);
            return sprintf(
                '%s__BAN_GIAO__%s_%s.%s',
                $machine->asset_code,
                $timeIn->format('Y-m-d'),
                $timeIn->format('His'),
                $ext
            );
        }

        if ($type === 'TRANSFER') {
            $timeOut = Carbon::parse($times['time_out']);
            $timeIn = Carbon::parse($times['time_in']);
            return sprintf(
                '%s__DIEU_CHUYEN__OUT_%s_%s__IN_%s_%s.%s',
                $machine->asset_code,
                $timeOut->format('Y-m-d'),
                $timeOut->format('His'),
                $timeIn->format('Y-m-d'),
                $timeIn->format('His'),
                $ext
            );
        }

        $timeOut = Carbon::parse($times['time_out']);
        return sprintf(
            '%s__TRA__%s_%s.%s',
            $machine->asset_code,
            $timeOut->format('Y-m-d'),
            $timeOut->format('His'),
            $ext
        );
    }
}
