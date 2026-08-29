<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Machine;
use App\Models\MachineHandoverCase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MachineHandoverService
{
    public function __construct(private readonly MachineService $machines, private readonly MachineHandoverAlertDispatcher $alerts) {}

    public function create(Machine $machine, array $files): MachineHandoverCase
    {
        if (! in_array($machine->status, ['WAIT_HANDOVER', 'RETURNED'], true)) {
            throw new BusinessRuleException('Chỉ nhận biên bản khi máy đang chờ bàn giao hoặc đã trả về.');
        }
        if ($files === []) throw ValidationException::withMessages(['documents' => 'Cần ít nhất một ảnh biên bản.']);

        return DB::transaction(function () use ($machine, $files) {
            $intake = $machine->intakeCase;
            $case = MachineHandoverCase::create([
                'machine_id' => $machine->id, 'machine_intake_case_id' => $intake?->id,
                'project_id' => $intake?->project_id, 'status' => 'OCR_PROCESSING',
            ]);
            foreach ($files as $file) $this->storeDocument($case, $file);
            return $case->fresh(['machine', 'intakeCase', 'documents']);
        });
    }

    public function confirm(MachineHandoverCase $case, array $data, User $user): MachineHandoverCase
    {
        return DB::transaction(function () use ($case, $data, $user) {
            $case->loadMissing(['machine', 'intakeCase', 'documents']);
            if ($case->status === 'HANDED_OVER') throw new BusinessRuleException('Biên bản này đã được xác nhận bàn giao.');
            $intakeProjectId = $case->intakeCase?->project_id;
            if ($intakeProjectId && (int) $data['project_id'] !== (int) $intakeProjectId) {
                throw ValidationException::withMessages(['project_id' => 'Dự án phải giữ theo hồ sơ tiếp nhận đã xác nhận.']);
            }
            $proof = $case->documents->first();
            if (! $proof) throw new BusinessRuleException('Biên bản chưa có ảnh gốc.');

            $case->update([
                'handover_date' => $data['handover_date'], 'project_id' => $data['project_id'],
                'command_center_id' => $data['command_center_id'], 'review_flags' => $this->reviewFlags($case, $data),
            ]);
            if (collect($case->review_flags)->contains(fn ($flag) => str_starts_with($flag, 'MISSING_'))) {
                $case->update(['status' => 'REVIEW']);
                throw ValidationException::withMessages(['handover' => 'Chưa đủ thông tin bắt buộc để bàn giao.']);
            }

            $this->machines->handoverToProject($case->machine_id, (int) $data['project_id'], (int) $data['command_center_id'], $data['handover_date'], $proof->storage_path);
            $case->update(['status' => 'HANDED_OVER', 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'last_error' => null]);
            return $case->fresh(['machine', 'project', 'commandCenter']);
        });
    }

    public function notifyWaitingActivation(MachineHandoverCase $case): void { $this->alerts->waitingActivation($case); }

    public function readyFlags(MachineHandoverCase $case): array
    {
        return $this->reviewFlags($case, [
            'handover_date' => $case->handover_date, 'project_id' => $case->project_id,
            'command_center_id' => $case->command_center_id,
        ]);
    }

    private function reviewFlags(MachineHandoverCase $case, array $data): array
    {
        $flags = collect($case->review_flags ?? [])->reject(fn ($flag) => str_starts_with($flag, 'MISSING_'))->values();
        if (blank($data['handover_date'] ?? null)) $flags->push('MISSING_HANDOVER_DATE');
        if (blank($data['project_id'] ?? null)) $flags->push('MISSING_PROJECT');
        if (blank($data['command_center_id'] ?? null)) $flags->push('MISSING_COMMAND_CENTER');
        if ($case->documents()->doesntExist()) $flags->push('MISSING_EVIDENCE');
        return $flags->unique()->values()->all();
    }

    private function storeDocument(MachineHandoverCase $case, UploadedFile $file): void
    {
        $hash = hash_file('sha256', $file->getRealPath());
        if ($case->documents()->where('sha256', $hash)->exists()) return;
        $path = $file->store("documents/machines/{$case->machine->asset_code}/handovers/{$case->id}", 'public');
        $document = $case->documents()->create([
            'storage_disk' => 'public', 'storage_path' => $path, 'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(), 'sha256' => $hash, 'byte_size' => $file->getSize(),
        ]);
        $document->ocrJob()->create(['status' => 'PENDING']);
    }

}
