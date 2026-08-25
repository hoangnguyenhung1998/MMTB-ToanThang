<?php

namespace Tests\Feature\Reconciliation;

use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationExportValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationExportValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_hours_at_two_bch_block_export(): void
    {
        [$period, $machine, $project, $firstBch, $secondBch] = $this->baseData();
        $this->row($period, $machine, $project, $firstBch, '07:00:00', '11:00:00');
        $this->row($period, $machine, $project, $secondBch, '07:00:00', '11:00:00');

        $result = app(ReconciliationExportValidator::class)->validate($period);

        $this->assertFalse($result['can_export']);
        $this->assertStringContainsString('giống hệt nhau', $result['blocking']->implode(' '));
    }

    public function test_different_non_overlapping_hours_at_two_bch_are_allowed(): void
    {
        [$period, $machine, $project, $firstBch, $secondBch] = $this->baseData();
        $this->row($period, $machine, $project, $firstBch, '07:00:00', '11:00:00');
        $this->row($period, $machine, $project, $secondBch, '13:30:00', '17:30:00');

        $result = app(ReconciliationExportValidator::class)->validate($period);

        $this->assertTrue($result['can_export']);
        $this->assertTrue($result['blocking']->isEmpty());
    }

    public function test_partially_overlapping_hours_create_warning(): void
    {
        [$period, $machine, $project, $firstBch, $secondBch] = $this->baseData();
        $this->row($period, $machine, $project, $firstBch, '07:00:00', '12:00:00');
        $this->row($period, $machine, $project, $secondBch, '11:00:00', '17:30:00');

        $result = app(ReconciliationExportValidator::class)->validate($period);

        $this->assertTrue($result['can_export']);
        $this->assertStringContainsString('chồng lấn', $result['warnings']->implode(' '));
    }

    public function test_active_machine_without_bch_assignment_blocks_export(): void
    {
        [$period] = $this->baseData();

        $result = app(ReconciliationExportValidator::class)->validate($period);

        $this->assertFalse($result['can_export']);
        $this->assertStringContainsString('không có lịch phân BCH', $result['blocking']->implode(' '));
    }

    private function baseData(): array
    {
        $period = ReconciliationPeriod::query()->create([
            'name' => 'Tháng 8/2026', 'type' => 'MONTHLY',
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'status' => 'CONFIRMED',
        ]);
        $machine = DB::table('machines')->insertGetId([
            'asset_code' => 'VT-XL0001', 'company' => 'VINALPHA', 'chassis_no' => 'TEST-15-1',
            'status' => 'ACTIVE', 'returned_to_app' => true, 'gps_file_added' => false,
        ]);
        $project = DB::table('projects')->insertGetId(['name' => 'Dự án A']);
        $firstBch = DB::table('command_centers')->insertGetId(['name' => 'BCH A']);
        $secondBch = DB::table('command_centers')->insertGetId(['name' => 'BCH B']);

        return [$period, $machine, $project, $firstBch, $secondBch];
    }

    private function row($period, int $machine, int $project, int $bch, string $start, string $end): void
    {
        $assignment = DB::table('machine_assignments')->insertGetId([
            'machine_id' => $machine, 'project_id' => $project, 'command_center_id' => $bch,
            'time_in' => '2026-08-15 '.$start, 'time_out' => '2026-08-15 '.$end,
        ]);
        DB::table('reconciliation_rows')->insert([
            'reconciliation_period_id' => $period->id, 'machine_id' => $machine,
            'machine_assignment_id' => $assignment, 'work_date' => '2026-08-15',
            'segment_start' => $start, 'segment_end' => $end,
            'project_id' => $project, 'command_center_id' => $bch, 'status' => 'CONFIRMED',
        ]);
    }
}
