<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Machine;
use App\Models\MachineIntakeCase;
use App\Models\MachineIntakeEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MachineIntakeDuplicateService
{
    public function conflict(MachineIntakeCase $case, ?string $chassis = null): ?array
    {
        $normalized = $this->normalize($chassis ?? (string) $case->chassis_no);
        if ($normalized === '') {
            return null;
        }

        $machine = Machine::query()
            ->where('chassis_no', $normalized)
            ->when($case->machine_id, fn ($query) => $query->whereKeyNot($case->machine_id))
            ->first();
        if ($machine) {
            return ['type' => 'machine', 'machine' => $machine, 'intake' => null];
        }

        $intake = MachineIntakeCase::query()
            ->whereKeyNot($case->id)
            ->where('chassis_no', $normalized)
            ->where('status', '!=', 'DUPLICATE')
            ->first();

        return $intake ? ['type' => 'intake', 'machine' => null, 'intake' => $intake] : null;
    }

    public function assertAvailable(MachineIntakeCase $case, ?string $chassis = null): void
    {
        $conflict = $this->conflict($case, $chassis);
        if (! $conflict) {
            return;
        }

        if ($conflict['type'] === 'machine') {
            throw new BusinessRuleException("Số khung đã thuộc máy {$conflict['machine']->asset_code}. Không được gửi BCH cấp mã mới.");
        }

        throw new BusinessRuleException("Số khung đang có trong hồ sơ {$conflict['intake']->reference}. Hãy xử lý hồ sơ đó trước.");
    }

    public function closeAsDuplicate(MachineIntakeCase $case, User $user): MachineIntakeCase
    {
        return DB::transaction(function () use ($case, $user) {
            $locked = MachineIntakeCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->machine_id || $locked->status === 'DUPLICATE') {
                throw new BusinessRuleException('Hồ sơ này đã được xử lý, không thể đóng trùng.');
            }

            $conflict = $this->conflict($locked);
            if (! $conflict) {
                throw new BusinessRuleException('Không còn phát hiện trùng số khung. Hãy kiểm tra lại dữ liệu trước khi đóng hồ sơ.');
            }

            $machine = $conflict['machine'];
            $intake = $conflict['intake'];
            $reason = $machine
                ? "Trùng số khung với máy {$machine->asset_code}"
                : "Trùng số khung với hồ sơ {$intake->reference}";

            $locked->update([
                'status' => 'DUPLICATE',
                'duplicate_machine_id' => $machine?->id,
                'duplicate_reason' => $reason,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'last_error' => null,
            ]);
            $locked->emailReplies()->where('status', 'PENDING')->update(['status' => 'REJECTED_DUPLICATE']);
            MachineIntakeEvent::create([
                'machine_intake_case_id' => $locked->id,
                'user_id' => $user->id,
                'event' => 'intake.closed_as_duplicate',
                'properties' => [
                    'reason' => $reason,
                    'duplicate_machine_id' => $machine?->id,
                    'duplicate_intake_id' => $intake?->id,
                ],
                'occurred_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    private function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9-]/', '', strtoupper(trim($value))));
    }
}
