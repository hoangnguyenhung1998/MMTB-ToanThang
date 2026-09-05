<?php

namespace Tests\Feature\Reconciliation;

use App\Models\ActivityLog;
use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationPeriodService;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use App\Services\Reconciliation\ReconciliationAssignmentBchResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationAppendAndRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_append_is_idempotent_and_preserves_edited_and_reviewed_rows(): void
    {
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-09');
        $first = $this->assignment('2026-09-01');
        $service->syncMonthly($period);
        $row = $period->rows()->first();
        $row->update(['regular_minutes' => 321, 'manually_edited_at' => now(), 'status' => 'REVIEWED']);
        $snapshot = $row->fresh()->getAttributes();
        $period->update(['status' => 'REVIEWING']);
        $new = $this->assignment('2026-09-20');
        $service->syncMonthly($period->fresh());
        $service->syncMonthly($period->fresh());
        $this->assertSame(41, $period->rows()->count());
        $this->assertSame($snapshot, $row->fresh()->getAttributes());
        $this->assertSame('REVIEWING', $period->fresh()->status);
        $this->assertSame(11, $period->rows()->where('machine_id', $new->machine_id)->count());
        $this->assertDatabaseMissing('reconciliation_rows', ['machine_id' => $new->machine_id, 'work_date' => '2026-09-19']);
    }

    public function test_confirmed_period_does_not_receive_new_rows(): void
    {
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-09');
        $period->update(['status' => 'CONFIRMED']);
        $this->assignment('2026-09-20');
        $service->syncMonthly($period);
        $this->assertSame(0, $period->rows()->count());
    }

    public function test_repair_uses_source_assignment_preserves_hours_and_records_history(): void
    {
        $assignment = $this->assignment('2026-09-30');
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-09');
        $service->syncMonthly($period);
        $row = $period->rows()->first();
        $row->update(['command_center_id' => null, 'regular_minutes' => 321, 'manually_edited_at' => now()]);
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(['repaired' => 1, 'removed' => 0, 'unresolved' => 0], $result);
        $this->assertEquals(321, $row->fresh()->regular_minutes);
        $this->assertEquals($assignment->command_center_id, $row->fresh()->command_center_id);
        $this->assertSame(1, ActivityLog::where('event', 'reconciliation.links_repaired')->count());
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 0], app(ReconciliationLinkRepairService::class)->repair($period, null));
    }

    public function test_missing_bch_in_source_assignment_is_not_guessed(): void
    {
        $assignment = $this->assignment('2026-09-30');
        $assignment->update(['command_center_id' => null]);
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-09');
        $service->syncMonthly($period);
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null));
        $this->assertNull($period->rows()->first()->command_center_id);
    }

    public function test_append_does_not_duplicate_orphaned_legacy_rows(): void
    {
        $this->assignment('2026-09-30');
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-09');
        $service->syncMonthly($period);
        $period->rows()->first()->update(['machine_assignment_id' => null]);
        $service->syncMonthly($period->fresh());
        $this->assertSame(1, $period->rows()->count());
    }

    public function test_changed_assignment_blocks_export_without_overwriting_reviewed_hours(): void
    {
        $assignment = $this->assignment('2026-09-29');
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-09');
        $service->syncMonthly($period);
        $row = $period->rows()->orderByDesc('work_date')->first();
        $row->update(['status' => 'REVIEWED', 'regular_minutes' => 321, 'manually_edited_at' => now()]);
        $assignment->update(['time_out' => '2026-09-29 12:00:00']);
        $service->syncMonthly($period->fresh());
        $result = app(\App\Services\Reconciliation\ReconciliationExportValidator::class)->validate($period);
        $this->assertFalse($result['can_export']);
        $this->assertStringContainsString('không còn khớp phân công nguồn', $result['blocking']->implode(' '));
        $this->assertEquals(321, $row->fresh()->regular_minutes);
    }

    public function test_repair_does_not_change_reviewed_rows(): void
    {
        $this->assignment('2026-09-30');
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-09');
        $service->syncMonthly($period);
        $row = $period->rows()->first();
        $row->update(['command_center_id' => null, 'status' => 'REVIEWED']);
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null));
        $this->assertNull($row->fresh()->command_center_id);
    }

    public function test_repair_replaces_stale_catalog_links_from_exact_source_assignment(): void
    {
        $assignment = $this->assignment('2026-09-30');
        $otherProject = Project::create(['name' => 'Dự án cũ']);
        $otherBch = CommandCenter::create(['name' => 'BCH cũ']);
        $service = app(ReconciliationPeriodService::class);
        $period = $service->ensureMonthly('2026-09');
        $service->syncMonthly($period);
        $row = $period->rows()->first();
        $row->update([
            'project_id' => $otherProject->id,
            'command_center_id' => $otherBch->id,
            'regular_minutes' => 321,
            'manually_edited_at' => now(),
        ]);

        $this->assertSame(['repaired' => 1, 'removed' => 0, 'unresolved' => 0], app(ReconciliationLinkRepairService::class)->repair($period, null));
        $this->assertSame($assignment->project_id, $row->fresh()->project_id);
        $this->assertSame($assignment->command_center_id, $row->fresh()->command_center_id);
        $this->assertEquals(321, $row->fresh()->regular_minutes);
    }

    public function test_repair_removes_only_unprotected_rows_outside_source_assignment(): void
    {
        $expired = $this->assignment('2026-08-01');
        $expired->update(['time_out' => '2026-08-31 17:30:00']);
        $period = app(ReconciliationPeriodService::class)->ensureMonthly('2026-09');
        $staleId = $period->rows()->create([
            'machine_id' => $expired->machine_id,
            'machine_assignment_id' => $expired->id,
            'work_date' => '2026-09-01',
            'segment_start' => '00:00:00',
            'segment_end' => '23:59:59',
            'project_id' => $expired->project_id,
            'command_center_id' => $expired->command_center_id,
            'status' => 'DRAFT',
        ])->id;

        $this->assertSame(['repaired' => 0, 'removed' => 1, 'unresolved' => 0], app(ReconciliationLinkRepairService::class)->repair($period, null));
        $this->assertDatabaseMissing('reconciliation_rows', ['id' => $staleId]);
        $this->assertSame(1, ActivityLog::where('event', 'reconciliation.stale_row_removed')->count());
    }

    public function test_repair_removes_stale_evidence_row_when_current_row_has_identical_evidence(): void
    {
        $expired = $this->assignment('2026-08-01');
        $expired->update(['time_out' => '2026-08-31 17:30:00']);
        $current = MachineAssignment::create([
            'machine_id' => $expired->machine_id,
            'project_id' => $expired->project_id,
            'command_center_id' => CommandCenter::create(['name' => 'BCH hiện tại'])->id,
            'time_in' => '2026-09-01 00:00:00',
        ]);
        $period = app(ReconciliationPeriodService::class)->ensureMonthly('2026-09');
        $common = [
            'machine_id' => $expired->machine_id,
            'work_date' => '2026-09-04',
            'segment_start' => '00:00:00',
            'segment_end' => '23:59:59',
            'status' => 'DRAFT',
            'daily_ocr_job_ids' => [12, 13],
            'journal_row_ids' => [21],
            'ai_reconciliation_job_id' => 31,
        ];
        $stale = $period->rows()->create($common + [
            'machine_assignment_id' => $expired->id,
            'project_id' => $expired->project_id,
            'command_center_id' => $expired->command_center_id,
        ]);
        $currentRow = $period->rows()->create($common + [
            'machine_assignment_id' => $current->id,
            'project_id' => $current->project_id,
            'command_center_id' => $current->command_center_id,
        ]);

        $this->assertSame(['repaired' => 0, 'removed' => 1, 'unresolved' => 0], app(ReconciliationLinkRepairService::class)->repair($period, null));
        $this->assertNull($stale->fresh());
        $this->assertNotNull($currentRow->fresh());
        $this->assertSame([12, 13], $currentRow->fresh()->daily_ocr_job_ids);
    }

    public function test_confirmed_historical_bch_restores_rows_and_assignment_day_segments(): void
    {
        $key = uniqid();
        $machine = Machine::create(['asset_code' => $key, 'chassis_no' => $key, 'company' => 'SGC', 'status' => 'ACTIVE']);
        $project = Project::create(['name' => 'Dự án']);
        $bch = CommandCenter::create(['name' => 'TĐXD 02.4']);
        $old = MachineAssignment::create([
            'machine_id' => $machine->id, 'project_id' => $project->id, 'command_center_id' => null,
            'time_in' => '2026-08-01 08:00:00', 'time_out' => '2026-08-03 09:39:00',
        ]);
        $current = MachineAssignment::create([
            'machine_id' => $machine->id, 'project_id' => $project->id, 'command_center_id' => $bch->id,
            'time_in' => '2026-08-03 21:39:00',
        ]);
        $period = app(ReconciliationPeriodService::class)->ensureMonthly('2026-08');
        $oldRow = $period->rows()->create([
            'machine_id' => $machine->id, 'machine_assignment_id' => $old->id, 'work_date' => '2026-08-03',
            'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'project_id' => $project->id,
            'command_center_id' => null, 'status' => 'DRAFT',
        ]);
        $currentRow = $period->rows()->create([
            'machine_id' => $machine->id, 'machine_assignment_id' => $current->id, 'work_date' => '2026-08-03',
            'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'project_id' => $project->id,
            'command_center_id' => $bch->id, 'status' => 'DRAFT',
        ]);

        $result = app(ReconciliationAssignmentBchResolutionService::class)->resolve($period, $old, $bch, null);
        $this->assertSame(1, $result['updated']);
        $this->assertSame($bch->id, $oldRow->fresh()->command_center_id);
        $this->assertSame('09:39:00', $oldRow->fresh()->segment_end);
        $this->assertSame('21:39:00', $currentRow->fresh()->segment_start);
        $this->assertNull($old->fresh()->command_center_id);
        $this->assertDatabaseHas('machine_assignment_bch_resolutions', ['machine_assignment_id' => $old->id, 'command_center_id' => $bch->id]);
        $validation = app(\App\Services\Reconciliation\ReconciliationExportValidator::class)->validate($period);
        $this->assertTrue($validation['can_export']);
    }

    private function assignment(string $date): MachineAssignment
    {
        $key = uniqid();
        $machine = Machine::create(['asset_code' => $key, 'chassis_no' => $key, 'company' => 'SGC', 'status' => 'ACTIVE']);
        $project = Project::firstOrCreate(['name' => 'Dự án']);
        $bch = CommandCenter::firstOrCreate(['name' => 'BCH']);
        return MachineAssignment::create(['machine_id' => $machine->id, 'project_id' => $project->id, 'command_center_id' => $bch->id, 'time_in' => $date.' 07:00:00']);
    }
}
