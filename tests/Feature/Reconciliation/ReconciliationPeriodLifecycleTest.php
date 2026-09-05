<?php

namespace Tests\Feature\Reconciliation;

use App\Models\MachineAssignment;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationPeriodLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_period_is_unique_and_uses_full_calendar_month(): void
    {
        $service = app(ReconciliationPeriodService::class);

        $first = $service->ensureMonthly('2026-07');
        $second = $service->ensureMonthly('2026-07-18');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('2026-07-01', $first->date_from->toDateString());
        $this->assertSame('2026-07-31', $first->date_to->toDateString());
        $this->assertDatabaseCount('reconciliation_periods', 1);
    }

    public function test_live_monthly_draft_adds_a_machine_assigned_during_the_month(): void
    {
        [$machineId, $projectId, $commandCenterId] = $this->baseData();
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-07');
        $service->syncMonthly($period);

        MachineAssignment::query()->create([
            'machine_id' => $machineId,
            'project_id' => $projectId,
            'command_center_id' => $commandCenterId,
            'time_in' => '2026-07-20 07:00:00',
            'time_out' => null,
        ]);

        $service->syncMonthly($period->fresh());

        $this->assertDatabaseCount('reconciliation_rows', 12);
        $this->assertDatabaseMissing('reconciliation_rows', ['work_date' => '2026-07-19']);
        $this->assertDatabaseHas('reconciliation_rows', [
            'machine_id' => $machineId,
            'work_date' => '2026-07-20',
            'change_type' => 'HANDOVER',
        ]);
    }

    public function test_reviewing_period_appends_missing_rows_without_changing_review_state(): void
    {
        [$machineId, $projectId, $commandCenterId] = $this->baseData();
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-07');
        $period->update(['status' => 'REVIEWING']);

        MachineAssignment::query()->create([
            'machine_id' => $machineId,
            'project_id' => $projectId,
            'command_center_id' => $commandCenterId,
            'time_in' => '2026-07-20 07:00:00',
            'time_out' => null,
        ]);

        $service->syncMonthly($period->fresh());

        $this->assertDatabaseCount('reconciliation_rows', 12);
        $this->assertSame('REVIEWING', $period->fresh()->status);
    }

    public function test_cleanup_command_only_deletes_old_unreviewed_drafts_after_execute(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        $old = ReconciliationPeriod::query()->create([
            'name' => 'Nháp tháng 6', 'type' => 'MONTHLY',
            'date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'status' => 'DRAFT',
        ]);
        $current = ReconciliationPeriod::query()->create([
            'name' => 'Nháp tháng 8', 'type' => 'MONTHLY',
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'status' => 'DRAFT',
        ]);

        $this->artisan('reconciliation:cleanup-drafts')->assertSuccessful();
        $this->assertDatabaseHas('reconciliation_periods', ['id' => $old->id]);

        $this->artisan('reconciliation:cleanup-drafts --execute')->assertSuccessful();
        $this->assertDatabaseMissing('reconciliation_periods', ['id' => $old->id]);
        $this->assertDatabaseHas('reconciliation_periods', ['id' => $current->id]);

        Carbon::setTestNow();
    }

    private function baseData(): array
    {
        $projectId = DB::table('projects')->insertGetId(['name' => 'Dự án A']);
        $commandCenterId = DB::table('command_centers')->insertGetId(['name' => 'BCH 01']);
        $machineId = DB::table('machines')->insertGetId([
            'asset_code' => 'VT-XL0001',
            'company' => 'VINALPHA',
            'chassis_no' => 'LIFECYCLE-0001',
            'status' => 'ACTIVE',
            'returned_to_app' => true,
            'gps_file_added' => false,
        ]);

        return [$machineId, $projectId, $commandCenterId];
    }
}
