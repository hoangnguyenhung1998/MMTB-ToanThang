<?php

namespace Tests\Feature\Reconciliation;

use App\Models\MachineAssignment;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ReconciliationGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_rows_only_from_handover_date_for_a_new_machine(): void
    {
        [$machineId, $projectId, $commandCenterId] = $this->createBaseData();

        MachineAssignment::query()->create([
            'machine_id' => $machineId,
            'project_id' => $projectId,
            'command_center_id' => $commandCenterId,
            'time_in' => '2026-07-10 08:00:00',
            'time_out' => null,
        ]);

        $period = $this->period('2026-07-01', '2026-07-31');
        app(ReconciliationGenerator::class)->generate($period);

        $this->assertDatabaseCount('reconciliation_rows', 22);
        $this->assertDatabaseMissing('reconciliation_rows', [
            'machine_id' => $machineId,
            'work_date' => '2026-07-09',
        ]);
        $this->assertDatabaseHas('reconciliation_rows', [
            'machine_id' => $machineId,
            'work_date' => '2026-07-10',
            'project_id' => $projectId,
            'command_center_id' => $commandCenterId,
            'change_type' => 'HANDOVER',
            'status' => 'DRAFT',
        ]);
    }

    public function test_it_stops_generating_after_return_date(): void
    {
        [$machineId, $projectId, $commandCenterId] = $this->createBaseData();

        MachineAssignment::query()->create([
            'machine_id' => $machineId,
            'project_id' => $projectId,
            'command_center_id' => $commandCenterId,
            'time_in' => '2026-07-01 07:00:00',
            'time_out' => '2026-07-20 17:00:00',
        ]);

        $period = $this->period('2026-07-01', '2026-07-31');
        app(ReconciliationGenerator::class)->generate($period);

        $this->assertDatabaseCount('reconciliation_rows', 20);
        $this->assertDatabaseHas('reconciliation_rows', [
            'machine_id' => $machineId,
            'work_date' => '2026-07-20',
            'change_type' => 'ASSIGNMENT_END',
        ]);
        $this->assertDatabaseMissing('reconciliation_rows', [
            'machine_id' => $machineId,
            'work_date' => '2026-07-21',
        ]);
    }

    public function test_transfer_day_keeps_both_bch_segments_when_hours_are_different(): void
    {
        [$machineId, $projectA, $commandCenterA] = $this->createBaseData();
        $projectB = DB::table('projects')->insertGetId(['name' => 'Dự án B']);
        $commandCenterB = DB::table('command_centers')->insertGetId(['name' => 'BCH 02']);

        MachineAssignment::query()->create([
            'machine_id' => $machineId,
            'project_id' => $projectA,
            'command_center_id' => $commandCenterA,
            'time_in' => '2026-07-01 07:00:00',
            'time_out' => '2026-07-15 12:00:00',
        ]);

        $newAssignment = MachineAssignment::query()->create([
            'machine_id' => $machineId,
            'project_id' => $projectB,
            'command_center_id' => $commandCenterB,
            'time_in' => '2026-07-15 12:00:00',
            'time_out' => null,
        ]);

        $period = $this->period('2026-07-01', '2026-07-31');
        app(ReconciliationGenerator::class)->generate($period);

        $this->assertDatabaseCount('reconciliation_rows', 32);
        $this->assertSame(2, DB::table('reconciliation_rows')
            ->where('machine_id', $machineId)
            ->whereDate('work_date', '2026-07-15')
            ->count());
        $this->assertDatabaseHas('reconciliation_rows', [
            'machine_id' => $machineId,
            'work_date' => '2026-07-15',
            'project_id' => $projectA,
            'command_center_id' => $commandCenterA,
            'segment_start' => '00:00:00',
            'segment_end' => '12:00:00',
        ]);
        $this->assertDatabaseHas('reconciliation_rows', [
            'machine_id' => $machineId,
            'work_date' => '2026-07-15',
            'machine_assignment_id' => $newAssignment->id,
            'project_id' => $projectB,
            'command_center_id' => $commandCenterB,
            'change_type' => 'TRANSFER_IN',
            'segment_start' => '12:00:00',
            'segment_end' => '23:59:59',
        ]);
    }

    public function test_it_uses_driver_history_for_each_work_date(): void
    {
        [$machineId, $projectId, $commandCenterId] = $this->createBaseData();
        $driverA = DB::table('drivers')->insertGetId(['name' => 'Lái máy A']);
        $driverB = DB::table('drivers')->insertGetId(['name' => 'Lái máy B']);

        MachineAssignment::query()->create([
            'machine_id' => $machineId,
            'project_id' => $projectId,
            'command_center_id' => $commandCenterId,
            'time_in' => '2026-07-01 07:00:00',
            'time_out' => null,
        ]);

        DB::table('machine_driver_histories')->insert([
            [
                'machine_id' => $machineId,
                'driver_id' => $driverA,
                'started_at' => '2026-07-01 07:00:00',
                'ended_at' => '2026-07-15 12:00:00',
            ],
            [
                'machine_id' => $machineId,
                'driver_id' => $driverB,
                'started_at' => '2026-07-15 12:00:00',
                'ended_at' => null,
            ],
        ]);

        $period = $this->period('2026-07-01', '2026-07-31');
        app(ReconciliationGenerator::class)->generate($period);

        $this->assertDatabaseHas('reconciliation_rows', [
            'machine_id' => $machineId,
            'work_date' => '2026-07-14',
            'driver_id' => $driverA,
        ]);
        $this->assertDatabaseHas('reconciliation_rows', [
            'machine_id' => $machineId,
            'work_date' => '2026-07-15',
            'driver_id' => $driverB,
        ]);
    }

    public function test_it_does_not_regenerate_a_period_with_reviewed_data(): void
    {
        [$machineId, $projectId, $commandCenterId] = $this->createBaseData();

        MachineAssignment::query()->create([
            'machine_id' => $machineId,
            'project_id' => $projectId,
            'command_center_id' => $commandCenterId,
            'time_in' => '2026-07-01 07:00:00',
            'time_out' => null,
        ]);

        $period = $this->period('2026-07-01', '2026-07-02');
        app(ReconciliationGenerator::class)->generate($period);

        DB::table('reconciliation_rows')->where('reconciliation_period_id', $period->id)->limit(1)->update([
            'status' => 'REVIEWED',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Không thể tạo lại kỳ đã có dữ liệu được duyệt hoặc xác nhận.');

        app(ReconciliationGenerator::class)->generate($period->fresh());
    }

    private function createBaseData(): array
    {
        $projectId = DB::table('projects')->insertGetId(['name' => 'Dự án A']);
        $commandCenterId = DB::table('command_centers')->insertGetId(['name' => 'BCH 01']);
        $machineId = DB::table('machines')->insertGetId([
            'asset_code' => 'VT-XL0001',
            'company' => 'VINALPHA',
            'chassis_no' => 'CHASSIS-0001',
            'status' => 'ACTIVE',
            'returned_to_app' => true,
            'gps_file_added' => false,
        ]);

        return [$machineId, $projectId, $commandCenterId];
    }

    private function period(string $from, string $to): ReconciliationPeriod
    {
        return ReconciliationPeriod::query()->create([
            'name' => 'Kỳ kiểm thử',
            'type' => 'MONTHLY',
            'date_from' => $from,
            'date_to' => $to,
            'status' => 'DRAFT',
        ]);
    }
}
