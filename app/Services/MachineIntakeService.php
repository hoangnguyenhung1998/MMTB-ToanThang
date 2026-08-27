<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ActivityLog;
use App\Models\Machine;
use App\Models\MachineIntakeCase;
use App\Models\MachineIntakeDocument;
use App\Models\MachineIntakeEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MachineIntakeService
{
    public function __construct(
        private readonly MachineService $machines,
        private readonly MachineIntakeAlertDispatcher $alerts,
        private readonly MachineIntakeOcrService $ocr,
        private readonly MachineSpecificationNormalizer $normalizer,
    ) {}

    public function createDraft(array $data, array $files, User $user): MachineIntakeCase
    {
        return DB::transaction(function () use ($data, $files, $user) {
            $case = MachineIntakeCase::create($this->normalizedMachineData($data) + [
                'status' => 'NEW',
                'source_channel' => $data['source_channel'] ?? 'WEB',
            ]);
            $case->update(['reference' => sprintf('TN-%s-%06d', now()->format('Y'), $case->id)]);

            foreach ($files as $file) {
                $this->storeDocument($case, $file, $data['document_type'] ?? 'MACHINE_PHOTO');
            }

            $this->event($case, $user, 'intake.created', ['document_count' => count($files)]);
            $this->ocr->enqueueCase($case->load('documents'));
            return $case->refresh();
        });
    }

    public function confirm(MachineIntakeCase $case, array $data, User $user): MachineIntakeCase
    {
        if ($case->machine_id) {
            throw new BusinessRuleException('Hồ sơ đã tạo máy, không thể xác nhận lại dữ liệu nguồn.');
        }

        $normalized = $this->normalizer->normalize($this->normalizedMachineData($data));
        if (blank($normalized['chassis_no'] ?? null) || blank($normalized['engine_no'] ?? null)) {
            throw new BusinessRuleException('Phải xác nhận chính xác cả số khung và số máy trước khi gửi BCH.');
        }

        $case->update($normalized + [
            'status' => 'CONFIRMED', 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'last_error' => null,
        ]);
        $this->event($case, $user, 'intake.confirmed', ['chassis_no' => $case->chassis_no, 'engine_no' => $case->engine_no]);
        return $case->refresh();
    }

    public function markEmailSent(MachineIntakeCase $case, array $data, User $user): MachineIntakeCase
    {
        if ($case->status !== 'CONFIRMED') {
            throw new BusinessRuleException('Chỉ gửi BCH sau khi số khung và số máy đã được xác nhận.');
        }
        $case->update([
            'status' => 'WAIT_ASSET_CODE', 'email_thread_id' => $data['email_thread_id'] ?? null,
            'email_message_id' => $data['email_message_id'] ?? null, 'email_sent_at' => now(),
        ]);
        $this->event($case, $user, 'intake.email_sent', array_filter($data));
        return $case->refresh();
    }

    public function assignAssetCode(MachineIntakeCase $case, array $data, User $user, ?UploadedFile $evidence = null): MachineIntakeCase
    {
        $result = DB::transaction(function () use ($case, $data, $user, $evidence) {
            $locked = MachineIntakeCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->machine_id || ! in_array($locked->status, ['CONFIRMED', 'EMAIL_SENT', 'WAIT_ASSET_CODE'], true)) {
                throw new BusinessRuleException('Hồ sơ này không còn ở trạng thái chờ cấp mã.');
            }

            $codeRaw = trim((string) $data['asset_code']);
            $code = $this->normalizeIdentifier($codeRaw);
            $chassis = $this->normalizeIdentifier((string) $locked->chassis_no);
            if (Machine::where('asset_code', $code)->exists() || MachineIntakeCase::where('asset_code', $code)->whereKeyNot($locked->id)->exists()) {
                throw new BusinessRuleException('Mã máy đã tồn tại ở máy hoặc hồ sơ khác.');
            }
            if (Machine::where('chassis_no', $chassis)->exists()) {
                throw new BusinessRuleException('Số khung đã tồn tại trong danh sách máy.');
            }

            $evidencePath = $evidence?->store('machine-intakes/'.$locked->reference.'/asset-code', 'public');
            $machine = $this->machines->createMachine([
                'asset_code' => $code, 'company' => $locked->company, 'chassis_no' => $chassis,
                'engine_no' => $locked->engine_no, 'plate_no' => $locked->plate_no,
                'machine_type' => $locked->machine_type, 'manufacture_year' => $locked->manufacture_year,
                'brand' => $locked->brand, 'model_name' => $locked->model_name, 'capacity_class' => $locked->capacity_class, 'vehicle_axles' => $locked->vehicle_axles,
            ]);
            $locked->update([
                'machine_id' => $machine->id, 'status' => 'WAIT_HANDOVER', 'asset_code' => $code,
                'asset_code_raw' => $codeRaw, 'asset_code_source' => $data['asset_code_source'],
                'asset_code_source_note' => $data['asset_code_source_note'] ?? null,
                'code_evidence_path' => $evidencePath, 'code_received_at' => now(), 'last_error' => null,
            ]);
            $this->event($locked, $user, 'intake.asset_code_assigned', ['asset_code' => $code, 'source' => $data['asset_code_source'], 'machine_id' => $machine->id]);
            ActivityLog::create([
                'user_id' => $user->id, 'machine_id' => $machine->id, 'subject_type' => MachineIntakeCase::class,
                'subject_id' => $locked->id, 'event' => 'machine_intake.asset_code_assigned',
                'description' => "Cấp mã {$code} cho hồ sơ {$locked->reference}",
                'properties' => ['source' => $data['asset_code_source']], 'occurred_at' => now(),
            ]);
            return $locked->refresh();
        });

        if ($error = $this->alerts->codeAssigned($result)) {
            $result->update(['last_error' => 'Telegram: '.$error]);
            $this->event($result, $user, 'intake.telegram_failed', ['error' => $error]);
        } else {
            $this->event($result, $user, 'intake.telegram_sent');
        }
        return $result->refresh();
    }

    private function normalizedMachineData(array $data): array
    {
        $fields = ['company', 'plate_no', 'machine_type', 'brand', 'model_name', 'capacity_class', 'vehicle_axles', 'manufacture_year', 'project_id', 'command_center_id', 'driver_id', 'handover_at'];
        $result = array_intersect_key($data, array_flip($fields));
        foreach (['chassis_no', 'engine_no'] as $field) {
            if (array_key_exists($field, $data)) {
                $result[$field.'_raw'] = trim((string) $data[$field]);
                $result[$field] = $this->normalizeIdentifier((string) $data[$field]);
            }
        }
        return $result;
    }

    private function normalizeIdentifier(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9-]/', '', strtoupper(trim($value))));
    }

    private function storeDocument(MachineIntakeCase $case, UploadedFile $file, string $type): MachineIntakeDocument
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->store('machine-intakes/'.$case->reference.'/source', 'public');
        return $case->documents()->create([
            'document_type' => $type, 'storage_path' => $path, 'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(), 'sha256' => $hash, 'byte_size' => $file->getSize(),
        ]);
    }

    private function event(MachineIntakeCase $case, ?User $user, string $event, array $properties = []): void
    {
        MachineIntakeEvent::create(['machine_intake_case_id' => $case->id, 'user_id' => $user?->id, 'event' => $event, 'properties' => $properties ?: null, 'occurred_at' => now()]);
    }
}
