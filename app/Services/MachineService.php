<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CommandCenter;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineDriverHistory;
use App\Models\MachineEvent;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MachineService
{
    /**
     * Tạo máy mới với trạng thái mặc định WAIT_HANDOVER.
     *
     * @throws BusinessRuleException
     */
    public function createMachine(array $data): Machine
    {
        return DB::transaction(function () use ($data) {
            if (Machine::where('asset_code', $data['asset_code'] ?? null)->exists()) {
                throw new BusinessRuleException('Asset code đã tồn tại.');
            }

            if (Machine::where('chassis_no', $data['chassis_no'] ?? null)->exists()) {
                throw new BusinessRuleException('Chassis number đã tồn tại.');
            }

            $data['status'] = 'WAIT_HANDOVER';

            return Machine::create($data);
        });
    }

    /**
     * Bàn giao máy cho dự án.
     *
     * @throws BusinessRuleException
     */
    public function handoverToProject(
        int $machineId,
        int $projectId,
        int $commandCenterId,
        string $handoverDate,
        string $proofFilePath
    ): Machine {
        return DB::transaction(function () use ($machineId, $projectId, $commandCenterId, $handoverDate, $proofFilePath) {
            if (trim($proofFilePath) === '') {
                throw new BusinessRuleException('Bắt buộc có file chứng từ bàn giao.');
            }

            $machine = Machine::findOrFail($machineId);
            Project::findOrFail($projectId);
            CommandCenter::findOrFail($commandCenterId);
            $date = Carbon::parse($handoverDate)->toDateString();

            if (!in_array($machine->status, ['WAIT_HANDOVER', 'RETURNED'], true)) {
                throw new BusinessRuleException('Chỉ được bàn giao khi máy đang chờ bàn giao hoặc đã trả về.');
            }

            // Tạo assignment mới.
            MachineAssignment::create([
                'machine_id' => $machine->id,
                'project_id' => $projectId,
                'command_center_id' => $commandCenterId,
                'time_in' => Carbon::parse($date)->startOfDay(),
                'handover_date' => $date,
                'proof_file_path' => $proofFilePath,
            ]);

            // Ghi nhận sự kiện bàn giao.
            MachineEvent::create([
                'machine_id' => $machine->id,
                'project_id' => $projectId,
                'type' => 'HANDOVER',
                'occurred_at' => Carbon::parse($date)->startOfDay(),
                'event_date' => $date,
                'proof_file_path' => $proofFilePath,
                'to_project_id' => $projectId,
                'to_command_center_id' => $commandCenterId,
            ]);

            $machine->update(['status' => 'HANDED_OVER']);

            return $machine->refresh();
        });
    }

    /**
     * Kích hoạt máy.
     *
     * @throws BusinessRuleException
     */
    public function activateMachine(int $machineId, string $time): Machine
    {
        return DB::transaction(function () use ($machineId, $time) {
            $machine = Machine::findOrFail($machineId);

            if ($machine->status !== 'HANDED_OVER') {
                throw new BusinessRuleException('Chỉ được kích hoạt khi máy đã bàn giao.');
            }

            MachineEvent::create([
                'machine_id' => $machine->id,
                'type' => 'ACTIVATE',
                'occurred_at' => Carbon::parse($time),
            ]);

            $machine->update(['status' => 'ACTIVE']);

            return $machine->refresh();
        });
    }

    /**
     * Điều chuyển assignment giữa dự án/BCH.
     *
     * @throws BusinessRuleException
     */
    public function transferAssignment(
        int $machineId,
        int $fromProjectId,
        int $fromCommandCenterId,
        int $toProjectId,
        int $toCommandCenterId,
        string $timeOut,
        string $timeIn,
        ?string $proofFilePath
    ): Machine {
        return DB::transaction(function () use ($machineId, $fromProjectId, $fromCommandCenterId, $toProjectId, $toCommandCenterId, $timeOut, $timeIn, $proofFilePath) {
            $machine = Machine::findOrFail($machineId);
            Project::findOrFail($toProjectId);
            CommandCenter::findOrFail($toCommandCenterId);

            $openAssignment = MachineAssignment::where('machine_id', $machine->id)
                ->whereNull('time_out')
                ->lockForUpdate()
                ->first();

            if (!$openAssignment) {
                throw new BusinessRuleException('Không tìm thấy assignment đang mở để điều chuyển.');
            }

            if ($openAssignment->project_id !== $fromProjectId) {
                throw new BusinessRuleException('Dự án nguồn không khớp với assignment hiện tại.');
            }

            if ($openAssignment->command_center_id !== $fromCommandCenterId) {
                throw new BusinessRuleException('BCH nguồn không khớp với assignment hiện tại.');
            }

            $currentProjectId = $openAssignment->project_id;
            $currentCommandCenterId = $openAssignment->command_center_id;
            $sameProject = $currentProjectId === $toProjectId;

            if (!$sameProject && trim((string) $proofFilePath) === '') {
                throw new BusinessRuleException('Bắt buộc có file chứng từ điều chuyển khi đổi dự án.');
            }

            // Đóng assignment hiện tại.
            $openAssignment->update(['time_out' => Carbon::parse($timeOut)]);

            // Tạo assignment mới.
            MachineAssignment::create([
                'machine_id' => $machine->id,
                'project_id' => $toProjectId,
                'command_center_id' => $toCommandCenterId,
                'time_in' => Carbon::parse($timeIn),
                'proof_file_path' => $proofFilePath,
            ]);

            // Ghi nhận sự kiện điều chuyển.
            MachineEvent::create([
                'machine_id' => $machine->id,
                'project_id' => $toProjectId,
                'type' => 'TRANSFER',
                'occurred_at' => Carbon::parse($timeIn),
                'proof_file_path' => $sameProject ? null : $proofFilePath,
                'from_project_id' => $currentProjectId,
                'to_project_id' => $toProjectId,
                'from_command_center_id' => $currentCommandCenterId,
                'to_command_center_id' => $toCommandCenterId,
            ]);

            return $machine->refresh();
        });
    }

    /**
     * Trả máy về công ty.
     *
     * @throws BusinessRuleException
     */
    public function returnToCompany(
        int $machineId,
        string $timeOut,
        string $proofFilePath,
        bool $appReturnConfirmed
    ): Machine {
        return DB::transaction(function () use ($machineId, $timeOut, $proofFilePath, $appReturnConfirmed) {
            if (trim($proofFilePath) === '') {
                throw new BusinessRuleException('Bắt buộc có file chứng từ trả máy.');
            }

            $machine = Machine::findOrFail($machineId);

            if (!in_array($machine->status, ['HANDED_OVER', 'ACTIVE'], true)) {
                throw new BusinessRuleException('Chỉ được trả máy khi đang bàn giao hoặc hoạt động.');
            }

            $openAssignment = MachineAssignment::where('machine_id', $machine->id)
                ->whereNull('time_out')
                ->first();

            $fromProjectId = $openAssignment?->project_id;
            $fromCommandCenterId = $openAssignment?->command_center_id;

            if ($openAssignment) {
                $openAssignment->update(['time_out' => Carbon::parse($timeOut)]);
            }

            $openDriverHistory = MachineDriverHistory::where('machine_id', $machine->id)
                ->whereNull('ended_at')
                ->first();

            if ($openDriverHistory) {
                $openDriverHistory->update(['ended_at' => Carbon::parse($timeOut)]);
            }

            MachineEvent::create([
                'machine_id' => $machine->id,
                'project_id' => $fromProjectId,
                'type' => 'RETURN',
                'occurred_at' => Carbon::parse($timeOut),
                'proof_file_path' => $proofFilePath,
                'app_return_confirmed' => $appReturnConfirmed,
                'from_project_id' => $fromProjectId,
                'from_command_center_id' => $fromCommandCenterId,
            ]);

            $machine->update([
                'status' => 'RETURNED',
                'current_driver_id' => null,
                'returned_to_app' => $appReturnConfirmed,
            ]);

            return $machine->refresh();
        });
    }

    /**
     * Gán tài xế cho máy.
     *
     * @throws BusinessRuleException
     */
    public function assignDriver(int $machineId, int $driverId, string $startedAt): Machine
    {
        return DB::transaction(function () use ($machineId, $driverId, $startedAt) {
            $machine = Machine::findOrFail($machineId);
            Driver::findOrFail($driverId);

            if ($machine->current_driver_id === $driverId) {
                throw new BusinessRuleException('Tài xế này đang được gán cho xe.');
            }

            $openHistory = MachineDriverHistory::where('machine_id', $machine->id)
                ->whereNull('ended_at')
                ->first();

            if ($openHistory) {
                $openHistory->update(['ended_at' => Carbon::parse($startedAt)]);
            }

            MachineDriverHistory::create([
                'machine_id' => $machine->id,
                'driver_id' => $driverId,
                'started_at' => Carbon::parse($startedAt),
            ]);

            $machine->update(['current_driver_id' => $driverId]);

            return $machine->refresh();
        });
    }

    /**
     * Lấy thông tin hiện tại phục vụ UI.
     *
     * @throws ModelNotFoundException
     */
    public function getCurrentInfo(int $machineId): array
    {
        $machine = Machine::with(['currentDriver', 'assignments.project', 'assignments.commandCenter'])
            ->findOrFail($machineId);

        $currentAssignment = $machine->assignments
            ->whereNull('time_out')
            ->first();

        $lastAssignment = $machine->assignments
            ->sortByDesc('time_in')
            ->first();

        return [
            'asset_code' => $machine->asset_code,
            'status' => $machine->status,
            'current_project' => $currentAssignment?->project
                ? ['id' => $currentAssignment->project->id, 'name' => $currentAssignment->project->name]
                : null,
            'current_command_center' => $currentAssignment?->commandCenter
                ? ['id' => $currentAssignment->commandCenter->id, 'name' => $currentAssignment->commandCenter->name]
                : null,
            'last_time_in' => $lastAssignment?->time_in,
            'last_time_out' => $lastAssignment?->time_out,
            'current_driver' => $machine->currentDriver
                ? ['id' => $machine->currentDriver->id, 'name' => $machine->currentDriver->name]
                : null,
            'gps_file_added' => (bool) $machine->gps_file_added,
        ];
    }

    /**
     * Lấy lịch sử assignment và driver history.
     *
     * @throws ModelNotFoundException
     */
    public function getHistory(int $machineId): array
    {
        $machine = Machine::findOrFail($machineId);

        $assignments = MachineAssignment::with(['project', 'commandCenter'])
            ->where('machine_id', $machine->id)
            ->orderByDesc('time_in')
            ->get()
            ->map(fn (MachineAssignment $assignment) => [
                'project' => $assignment->project
                    ? ['id' => $assignment->project->id, 'name' => $assignment->project->name]
                    : null,
                'command_center' => $assignment->commandCenter
                    ? ['id' => $assignment->commandCenter->id, 'name' => $assignment->commandCenter->name]
                    : null,
                'time_in' => $assignment->time_in,
                'time_out' => $assignment->time_out,
            ]);

        $driverHistory = MachineDriverHistory::with('driver')
            ->where('machine_id', $machine->id)
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (MachineDriverHistory $history) => [
                'driver' => $history->driver
                    ? ['id' => $history->driver->id, 'name' => $history->driver->name]
                    : null,
                'started_at' => $history->started_at,
                'ended_at' => $history->ended_at,
            ]);

        $events = MachineEvent::with(['fromProject', 'toProject', 'fromCommandCenter', 'toCommandCenter'])
            ->where('machine_id', $machine->id)
            ->orderByDesc('occurred_at')
            ->get()
            ->map(fn (MachineEvent $event) => [
                'type' => $event->type,
                'occurred_at' => $event->occurred_at,
                'from_project' => $event->fromProject
                    ? ['id' => $event->fromProject->id, 'name' => $event->fromProject->name]
                    : null,
                'to_project' => $event->toProject
                    ? ['id' => $event->toProject->id, 'name' => $event->toProject->name]
                    : null,
                'from_command_center' => $event->fromCommandCenter
                    ? ['id' => $event->fromCommandCenter->id, 'name' => $event->fromCommandCenter->name]
                    : null,
                'to_command_center' => $event->toCommandCenter
                    ? ['id' => $event->toCommandCenter->id, 'name' => $event->toCommandCenter->name]
                    : null,
                'proof_file_path' => $event->proof_file_path,
            ]);

        return [
            'assignments' => $assignments,
            'driver_history' => $driverHistory,
            'events' => $events,
        ];
    }
}
