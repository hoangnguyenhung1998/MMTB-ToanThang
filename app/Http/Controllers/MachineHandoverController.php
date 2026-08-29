<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineHandoverCase;
use App\Models\Project;
use App\Services\MachineHandoverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MachineHandoverController extends Controller
{
    public function __construct(private readonly MachineHandoverService $service) {}

    public function store(Request $request, Machine $machine): RedirectResponse
    {
        $request->validate(['documents' => ['required', 'array', 'min:1', 'max:10'], 'documents.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,bmp,tif,tiff', 'max:10240']]);
        try { $case = $this->service->create($machine, $request->file('documents', [])); }
        catch (BusinessRuleException $exception) { return back()->withErrors(['handover' => $exception->getMessage()]); }
        return redirect()->route('machine-handovers.show', $case)->with('success', 'Đã lưu ảnh gốc và đưa biên bản vào hàng OCR.');
    }

    public function show(MachineHandoverCase $machineHandover): View
    {
        $machineHandover->load(['machine.intakeCase.project', 'project', 'commandCenter', 'documents']);
        return view('machine-handovers.show', [
            'case' => $machineHandover,
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'commandCenters' => CommandCenter::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function confirm(Request $request, MachineHandoverCase $machineHandover): RedirectResponse
    {
        $data = $request->validate([
            'handover_date' => ['required', 'date_format:Y-m-d'], 'project_id' => ['required', 'exists:projects,id'],
            'command_center_id' => ['required', 'exists:command_centers,id'],
        ]);
        try { $case = $this->service->confirm($machineHandover, $data, $request->user()); }
        catch (BusinessRuleException $exception) { return back()->withErrors(['handover' => $exception->getMessage()])->withInput(); }
        $this->service->notifyWaitingActivation($case);
        return redirect()->route('machines.show', $case->machine)->with('success', 'Đã bàn giao. Máy đang chờ anh kích hoạt thủ công.');
    }

    public function document(MachineHandoverCase $machineHandover, int $document): StreamedResponse
    {
        $source = $machineHandover->documents()->findOrFail($document);
        abort_unless(Storage::disk($source->storage_disk)->exists($source->storage_path), 404);
        return Storage::disk($source->storage_disk)->download($source->storage_path, $source->original_name, ['Content-Type' => $source->mime_type]);
    }
}
