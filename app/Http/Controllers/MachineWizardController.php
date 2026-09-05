<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\CommandCenter;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineDocument;
use App\Models\Project;
use App\Services\MachineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class MachineWizardController extends Controller
{
    private const SESSION_KEY = 'machine_wizard';

    public function __construct(private readonly MachineService $machineService)
    {
    }

    public function index(): RedirectResponse
    {
        return redirect()->route('machines.wizard.step1');
    }

    public function step1(Request $request): View
    {
        return $this->render($request, 1);
    }

    public function storeStep1(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'asset_code' => ['required', 'string', 'max:255', 'unique:machines,asset_code'],
            'company' => ['required', new \App\Rules\AvailableCompany()],
            'chassis_no' => ['required', 'string', 'max:255', 'unique:machines,chassis_no'],
            'engine_no' => ['nullable', 'string', 'max:255'],
            'plate_no' => ['nullable', 'string', 'max:255'],
            'machine_type' => ['nullable', 'string', 'max:255'],
            'manufacture_year' => ['nullable', 'integer', 'min:1900', 'max:' . (now()->year + 1)],
        ]);

        $wizard = $this->wizard($request);
        $wizard['machine'] = $validated;
        $wizard['last_completed_step'] = max((int) ($wizard['last_completed_step'] ?? 0), 1);

        $request->session()->put(self::SESSION_KEY, $wizard);

        return redirect()->route('machines.wizard.step2');
    }

    public function step2(Request $request): View|RedirectResponse
    {
        if (!$this->hasCompleted($request, 1)) {
            return redirect()
                ->route('machines.wizard.step1')
                ->withErrors(['wizard' => 'Anh cần hoàn thành bước Thông tin máy trước.']);
        }

        return $this->render($request, 2);
    }

    public function storeStep2(Request $request): RedirectResponse
    {
        if (!$this->hasCompleted($request, 1)) {
            return redirect()->route('machines.wizard.step1');
        }

        $validated = $request->validate([
            'skip_documents' => ['nullable', 'boolean'],
            'document_type' => ['nullable', 'required_unless:skip_documents,1', 'string', 'max:255'],
            'documents' => ['nullable', 'required_unless:skip_documents,1', 'array'],
            'documents.*' => ['file', 'max:204800'],
            'document_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $wizard = $this->wizard($request);
        $this->deleteStoredPaths($wizard['documents']['files'] ?? []);

        $storedFiles = [];

        if (!$request->boolean('skip_documents')) {
            foreach ($request->file('documents', []) as $file) {
                $storedFiles[] = [
                    'path' => $file->store($this->temporaryDirectory($request) . '/documents', 'public'),
                    'original_name' => $file->getClientOriginalName(),
                    'extension' => strtolower($file->getClientOriginalExtension()),
                ];
            }
        }

        $wizard['documents'] = [
            'skip' => $request->boolean('skip_documents'),
            'document_type' => $validated['document_type'] ?? null,
            'document_note' => $validated['document_note'] ?? null,
            'files' => $storedFiles,
        ];
        $wizard['last_completed_step'] = max((int) ($wizard['last_completed_step'] ?? 0), 2);

        $request->session()->put(self::SESSION_KEY, $wizard);

        return redirect()->route('machines.wizard.step3');
    }

    public function step3(Request $request): View|RedirectResponse
    {
        if (!$this->hasCompleted($request, 2)) {
            return redirect()
                ->route('machines.wizard.step1')
                ->withErrors(['wizard' => 'Anh cần hoàn thành các bước trước.']);
        }

        return $this->render($request, 3);
    }

    public function storeStep3(Request $request): RedirectResponse
    {
        if (!$this->hasCompleted($request, 2)) {
            return redirect()->route('machines.wizard.step1');
        }

        $validated = $request->validate([
            'driver_id' => ['nullable', 'exists:drivers,id'],
            'handover_now' => ['nullable', 'boolean'],
            'project_id' => ['nullable', 'required_if:handover_now,1', 'exists:projects,id'],
            'command_center_id' => ['nullable', 'required_if:handover_now,1', 'exists:command_centers,id'],
            'handover_time' => ['nullable', 'required_if:handover_now,1', 'date'],
            'proof_file' => ['nullable', 'required_if:handover_now,1', 'file', 'max:5120'],
            'handover_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $wizard = $this->wizard($request);
        $oldProof = $wizard['operation']['proof_file']['path'] ?? null;

        if ($oldProof) {
            Storage::disk('public')->delete($oldProof);
        }

        $proof = null;

        if ($request->boolean('handover_now') && $request->hasFile('proof_file')) {
            $file = $request->file('proof_file');
            $proof = [
                'path' => $file->store($this->temporaryDirectory($request) . '/proofs', 'public'),
                'original_name' => $file->getClientOriginalName(),
                'extension' => strtolower($file->getClientOriginalExtension()),
            ];
        }

        $wizard['operation'] = [
            'driver_id' => $validated['driver_id'] ?? null,
            'handover_now' => $request->boolean('handover_now'),
            'project_id' => $validated['project_id'] ?? null,
            'command_center_id' => $validated['command_center_id'] ?? null,
            'handover_time' => $validated['handover_time'] ?? null,
            'handover_note' => $validated['handover_note'] ?? null,
            'proof_file' => $proof,
        ];
        $wizard['last_completed_step'] = 3;

        $request->session()->put(self::SESSION_KEY, $wizard);

        return redirect()->route('machines.wizard.review');
    }

    public function review(Request $request): View|RedirectResponse
    {
        if (!$this->hasCompleted($request, 3)) {
            return redirect()
                ->route('machines.wizard.step1')
                ->withErrors(['wizard' => 'Anh cần hoàn thành các bước trước khi xác nhận.']);
        }

        return $this->render($request, 4);
    }

    public function finish(Request $request): RedirectResponse
    {
        if (!$this->hasCompleted($request, 3)) {
            return redirect()->route('machines.wizard.step1');
        }

        $wizard = $this->wizard($request);
        $machineData = $wizard['machine'] ?? [];
        $documents = $wizard['documents'] ?? [];
        $operation = $wizard['operation'] ?? [];

        if (empty($machineData['asset_code']) || empty($machineData['chassis_no'])) {
            return redirect()
                ->route('machines.wizard.step1')
                ->withErrors(['wizard' => 'Dữ liệu máy trong Wizard không đầy đủ.']);
        }

        if (($operation['handover_now'] ?? false) && empty($operation['proof_file']['path'])) {
            return redirect()
                ->route('machines.wizard.step3')
                ->withErrors(['proof_file' => 'Bắt buộc có biên bản bàn giao.']);
        }

        $finalPaths = [];

        try {
            $preparedDocuments = [];

            foreach ($documents['files'] ?? [] as $index => $file) {
                $extension = $file['extension'] ?: pathinfo($file['original_name'], PATHINFO_EXTENSION);
                $name = sprintf(
                    '%s__HO_SO__%02d__%s.%s',
                    Str::slug($machineData['asset_code'], '_'),
                    $index + 1,
                    now()->format('Ymd_His'),
                    $extension ?: 'dat'
                );

                $destination = "documents/machines/{$machineData['asset_code']}/{$name}";
                $this->movePublicFile($file['path'], $destination);
                $finalPaths[] = $destination;

                $preparedDocuments[] = [
                    'doc_type' => $documents['document_type'] ?? 'Hồ sơ khác',
                    'file_path' => $destination,
                ];
            }

            $proofPath = null;

            if ($operation['handover_now'] ?? false) {
                $proof = $operation['proof_file'];
                $extension = $proof['extension'] ?: pathinfo($proof['original_name'], PATHINFO_EXTENSION);
                $proofName = sprintf(
                    '%s__BAN_GIAO__%s.%s',
                    Str::slug($machineData['asset_code'], '_'),
                    now()->format('Ymd_His'),
                    $extension ?: 'dat'
                );

                $proofPath = "documents/machines/{$machineData['asset_code']}/proofs/{$proofName}";
                $this->movePublicFile($proof['path'], $proofPath);
                $finalPaths[] = $proofPath;
            }

            $machine = DB::transaction(function () use (
                $machineData,
                $preparedDocuments,
                $operation,
                $proofPath
            ): Machine {
                $machine = $this->machineService->createMachine($machineData);

                foreach ($preparedDocuments as $document) {
                    MachineDocument::create([
                        'machine_id' => $machine->id,
                        'doc_type' => $document['doc_type'],
                        'file_path' => $document['file_path'],
                    ]);
                }

                $startedAt = $operation['handover_time'] ?? now()->toDateTimeString();

                if (!empty($operation['driver_id'])) {
                    $this->machineService->assignDriver(
                        $machine->id,
                        (int) $operation['driver_id'],
                        $startedAt
                    );
                }

                if ($operation['handover_now'] ?? false) {
                    $this->machineService->handoverToProject(
                        $machine->id,
                        (int) $operation['project_id'],
                        (int) $operation['command_center_id'],
                        $operation['handover_time'],
                        (string) $proofPath
                    );
                }

                return $machine->refresh();
            });

            Storage::disk('public')->deleteDirectory($this->temporaryDirectory($request));
            $request->session()->forget(self::SESSION_KEY);

            return redirect()
                ->route('machines.show', $machine)
                ->with('success', 'Đã tạo máy đầy đủ bằng Machine Wizard.');
        } catch (BusinessRuleException $exception) {
            $this->deleteStoredPaths($finalPaths);

            return back()
                ->withErrors(['wizard' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->deleteStoredPaths($finalPaths);
            report($exception);

            return back()
                ->withErrors(['wizard' => 'Không thể hoàn tất Wizard. Dữ liệu đã được hoàn tác, anh kiểm tra log để xem chi tiết.']);
        }
    }

    public function cancel(Request $request): RedirectResponse
    {
        Storage::disk('public')->deleteDirectory($this->temporaryDirectory($request));
        $request->session()->forget(self::SESSION_KEY);

        return redirect()
            ->route('machines.index')
            ->with('success', 'Đã hủy Machine Wizard và xóa dữ liệu tạm.');
    }

    private function render(Request $request, int $step): View
    {
        $wizard = $this->wizard($request);
        $operation = $wizard['operation'] ?? [];

        return view('machines.wizard.index', [
            'wizard' => $wizard,
            'currentStep' => $step,
            'drivers' => $step === 3
                ? Driver::orderBy('name')->get(['id', 'name', 'phone'])
                : collect(),
            'projects' => $step === 3
                ? Project::orderBy('name')->get(['id', 'name'])
                : collect(),
            // command_centers không có project_id trong schema hiện tại.
            'commandCenters' => $step === 3
                ? CommandCenter::orderBy('name')->get(['id', 'name'])
                : collect(),
            'driver' => $step === 4 && !empty($operation['driver_id'])
                ? Driver::find($operation['driver_id'])
                : null,
            'project' => $step === 4 && !empty($operation['project_id'])
                ? Project::find($operation['project_id'])
                : null,
            'commandCenter' => $step === 4 && !empty($operation['command_center_id'])
                ? CommandCenter::find($operation['command_center_id'])
                : null,
        ]);
    }

    private function wizard(Request $request): array
    {
        return $request->session()->get(self::SESSION_KEY, [
            'machine' => [],
            'documents' => [],
            'operation' => [],
            'last_completed_step' => 0,
        ]);
    }

    private function hasCompleted(Request $request, int $step): bool
    {
        return (int) ($this->wizard($request)['last_completed_step'] ?? 0) >= $step;
    }

    private function temporaryDirectory(Request $request): string
    {
        return 'tmp/machine-wizard/' . $request->session()->getId();
    }

    private function movePublicFile(string $source, string $destination): void
    {
        $disk = Storage::disk('public');

        if (!$disk->exists($source)) {
            throw new \RuntimeException("Không tìm thấy file tạm: {$source}");
        }

        $directory = dirname($destination);

        if (!$disk->exists($directory)) {
            $disk->makeDirectory($directory);
        }

        if (!$disk->move($source, $destination)) {
            throw new \RuntimeException("Không thể chuyển file đến: {$destination}");
        }
    }

    private function deleteStoredPaths(array $files): void
    {
        foreach ($files as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : $file;

            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
