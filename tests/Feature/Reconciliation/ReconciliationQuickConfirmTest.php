<?php

namespace Tests\Feature\Reconciliation;

use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationQuickConfirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_grid_accepts_short_hour_and_quickly_confirms_complete_or_rest_day(): void
    {
        [$period, $row] = $this->data();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('reconciliation-rows.update', [$period, $row]), [
                'return_to' => 'period',
                'submit_action' => 'quick_confirm',
                'regular_morning_start' => '7',
                'regular_morning_end' => '11:00',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('reconciliation_rows', [
            'id' => $row->id,
            'regular_morning_start' => '07:00:00',
            'regular_morning_end' => '11:00:00',
            'status' => 'CONFIRMED',
            'reviewed_by' => $user->id,
            'confirmed_by' => $user->id,
        ]);

        $restRow = ReconciliationRow::query()->create([
            'reconciliation_period_id' => $period->id,
            'machine_id' => $row->machine_id,
            'machine_assignment_id' => $row->machine_assignment_id,
            'project_id' => $row->project_id,
            'command_center_id' => $row->command_center_id,
            'work_date' => '2026-08-11',
            'segment_start' => '00:00:00',
            'segment_end' => '23:59:59',
            'status' => 'DRAFT',
        ]);

        $this->actingAs($user)
            ->put(route('reconciliation-rows.update', [$period, $restRow]), [
                'return_to' => 'period',
                'submit_action' => 'quick_confirm',
                'regular_morning_start' => '',
                'regular_morning_end' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('reconciliation_rows', [
            'id' => $restRow->id,
            'regular_morning_start' => null,
            'regular_morning_end' => null,
            'status' => 'CONFIRMED',
        ]);
    }

    private function data(): array
    {
        $period = ReconciliationPeriod::query()->create([
            'name' => 'Tháng 8/2026', 'type' => 'MONTHLY',
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'status' => 'REVIEWING',
        ]);
        $project = DB::table('projects')->insertGetId(['name' => 'Dự án A']);
        $bch = DB::table('command_centers')->insertGetId(['name' => 'BCH A']);
        $machine = DB::table('machines')->insertGetId([
            'asset_code' => 'T-XL0034', 'company' => 'VINCONS', 'chassis_no' => 'QUICK-CONFIRM-1',
            'status' => 'ACTIVE', 'returned_to_app' => true, 'gps_file_added' => false,
        ]);
        $assignment = DB::table('machine_assignments')->insertGetId([
            'machine_id' => $machine, 'project_id' => $project, 'command_center_id' => $bch,
            'time_in' => '2026-08-01 07:00:00', 'time_out' => null,
        ]);
        $row = ReconciliationRow::query()->create([
            'reconciliation_period_id' => $period->id,
            'machine_id' => $machine,
            'machine_assignment_id' => $assignment,
            'project_id' => $project,
            'command_center_id' => $bch,
            'work_date' => '2026-08-10',
            'segment_start' => '00:00:00',
            'segment_end' => '23:59:59',
            'status' => 'DRAFT',
        ]);

        return [$period, $row];
    }
}
