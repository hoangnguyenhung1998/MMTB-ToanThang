<?php

namespace Tests\Feature\Reconciliation;

use App\Models\ActivityLog;
use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineAssignmentBchResolution;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationExportValidator;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationRepairTimelineTest extends TestCase
{
    use RefreshDatabase;

    private ReconciliationPeriod $period;

    private Machine $machine;

    private Project $project;

    private CommandCenter $bch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->period = app(ReconciliationPeriodService::class)->ensureMonthly('2026-09');
        $this->machine = Machine::create(['asset_code' => 'REPAIR', 'chassis_no' => 'REPAIR', 'company' => 'SGC', 'status' => 'ACTIVE']);
        $this->project = Project::create(['name' => 'Repair project']);
        $this->bch = CommandCenter::create(['name' => 'BCH A']);
    }

    public function test_single_assignment_is_a_read_only_noop(): void
    {
        $assignment = $this->assignment('2026-09-01');
        $row = $this->row($assignment, 10, ['regular_minutes' => 300, 'daily_ocr_job_ids' => [2, 1]]);
        $before = $row->getAttributes();
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 0], $this->repair());
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(0, ActivityLog::where('event', 'like', 'reconciliation.%')->count());
    }

    public function test_a_to_b_uses_effective_date_and_is_idempotent(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-14 23:59:59');
        $b = $this->assignment('2026-09-15', null, 'BCH B');
        for ($day = 1; $day <= 30; $day++) {
            $this->row($a, $day);
        }
        $this->assertSame(['repaired' => 16, 'removed' => 0, 'unresolved' => 0], $this->repair());
        foreach ($this->period->rows()->get() as $row) {
            $expected = $row->work_date->day <= 14 ? $a : $b;
            $this->assertSame($expected->id, (int) $row->machine_assignment_id);
            $this->assertSame($expected->command_center_id, (int) $row->command_center_id);
        }
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($this->period)['can_export']);
        $logs = ActivityLog::count();
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 0], $this->repair());
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_multiple_transfers_preserve_all_three_intervals(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-10 23:59:59');
        $b = $this->assignment('2026-09-11', '2026-09-20 23:59:59', 'BCH B');
        $c = $this->assignment('2026-09-21', null, 'BCH C');
        for ($day = 1; $day <= 30; $day++) {
            $this->row($a, $day);
        }
        $this->assertSame(['repaired' => 20, 'removed' => 0, 'unresolved' => 0], $this->repair());
        foreach ($this->period->rows()->get() as $row) {
            $expected = $row->work_date->day <= 10 ? $a : ($row->work_date->day <= 20 ? $b : $c);
            $this->assertSame($expected->id, (int) $row->machine_assignment_id);
            $this->assertSame($expected->command_center_id, (int) $row->command_center_id);
        }
    }

    public function test_return_keeps_whole_return_day_and_removes_empty_future_days(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-15 12:00:00');
        for ($day = 1; $day <= 30; $day++) {
            $this->row($a, $day);
        }
        $this->assertSame(['repaired' => 0, 'removed' => 15, 'unresolved' => 0], $this->repair());
        $this->assertSame('23:59:59', $this->period->rows()->whereDate('work_date', '2026-09-15')->first()->segment_end);
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($this->period)['can_export']);
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 0], $this->repair());
    }

    public function test_handover_resolves_orphan_only_after_effective_start(): void
    {
        $a = $this->assignment('2026-09-15 07:30:00');
        $early = $this->row(null, 14);
        $start = $this->row(null, 15);
        $later = $this->row(null, 16);
        $before = $early->getAttributes();
        $this->assertSame(['repaired' => 2, 'removed' => 0, 'unresolved' => 0], $this->repair());
        $this->assertSame($before, $early->fresh()->getAttributes());
        $this->assertSame('00:00:00', $start->fresh()->segment_start);
        $this->assertSame($a->id, (int) $later->fresh()->machine_assignment_id);
    }

    public function test_disjoint_same_day_transfer_segments_do_not_count_as_ambiguous(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-15 12:00:00');
        $b = $this->assignment('2026-09-15 13:00:00', null, 'BCH B');
        $old = $this->row($a, 15);
        $new = $this->row($b, 15);
        $this->assertSame(['repaired' => 1, 'removed' => 1, 'unresolved' => 0], $this->repair());
        $this->assertSame($b->id, $old->fresh()->machine_assignment_id);
        $this->assertSame('23:59:59', $old->fresh()->segment_end);
        $this->assertNull($new->fresh());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($this->period)['can_export']);
    }

    public function test_overlapping_assignments_remain_unresolved_even_with_source_id(): void
    {
        $a = $this->assignment('2026-09-01');
        $this->assignment('2026-09-05', null, 'BCH B');
        $linked = $this->row($a, 10, ['command_center_id' => null]);
        $orphan = $this->row(null, 11);
        $before = [$linked->getAttributes(), $orphan->getAttributes()];
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 2], $this->repair());
        $this->assertSame($before, [$linked->fresh()->getAttributes(), $orphan->fresh()->getAttributes()]);
        $this->assertSame(0, ActivityLog::where('event', 'like', 'reconciliation.%')->count());
    }

    public function test_exact_source_catalog_repair_leaves_manual_evidence_payload_for_review(): void
    {
        config(['daily_photos.enabled' => true]);
        $a = $this->assignment('2026-09-01');
        $row = $this->row($a, 10, ['command_center_id' => null, 'manually_edited_at' => now(),
            'regular_minutes' => 321, 'work_content' => 'HUMAN', 'work_location' => 'Manual location',
            'daily_ocr_job_ids' => [1, 2], 'journal_row_ids' => [3], 'ai_reconciliation_job_id' => 99,
            'daily_intervals' => [['start' => '07:00:00', 'end' => '12:00:00']], 'evidence_signature' => str_repeat('a', 64)]);
        $before = $row->getAttributes();
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 1], $this->repair());
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_terminal_and_review_timestamp_rows_are_not_changed(): void
    {
        $a = $this->assignment('2026-09-01');
        $states = [['status' => 'REVIEWED'], ['status' => 'CONFIRMED'], ['status' => 'REJECTED'],
            ['reviewed_at' => now()], ['confirmed_at' => now()]];
        $rows = [];
        foreach ($states as $i => $state) {
            $row = $this->row($a, $i + 1, $state + ['command_center_id' => null]);
            $rows[] = [$row, $row->getAttributes()];
        }
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 5], $this->repair());
        foreach ($rows as [$row, $before]) {
            $this->assertSame($before, $row->fresh()->getAttributes());
        }
    }

    public function test_stale_automatic_payload_relinks_while_manual_and_reviewed_remain_protected(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-14 23:59:59');
        $b = $this->assignment('2026-09-15', null, 'BCH B');
        $states = [['manually_edited_at' => now()], ['daily_ocr_job_ids' => [1]], ['regular_minutes' => 123],
            ['work_content' => 'HUMAN'], ['status' => 'REVIEWED']];
        foreach ($states as $i => $state) {
            $row = $this->row($a, 15 + $i, $state);
            $snapshots[] = [$row, $row->getAttributes()];
        }
        $this->assertSame(['repaired' => 3, 'removed' => 0, 'unresolved' => 2], $this->repair());
        foreach ($snapshots as [$row, $before]) {
            $after = $row->fresh()->getAttributes();
            if ($row->status === 'REVIEWED' || $row->manually_edited_at) {
                $this->assertSame($before, $after);
            } else {
                $this->assertSame($b->id, (int) $after['machine_assignment_id']);
                foreach (['machine_assignment_id', 'project_id', 'command_center_id', 'updated_at'] as $field) {
                    unset($before[$field], $after[$field]);
                }
                $this->assertSame($before, $after);
            }
        }
    }

    public function test_same_evidence_ids_with_different_hours_cannot_be_deduplicated(): void
    {
        $a = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $b = $this->assignment('2026-09-01', null, 'BCH B');
        $stale = $this->row($a, 10, ['daily_ocr_job_ids' => [1, 2], 'regular_minutes' => 321]);
        $this->row($b, 10, ['daily_ocr_job_ids' => [1, 2], 'regular_minutes' => 300]);
        $before = $stale->getAttributes();
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 1], $this->repair());
        $this->assertSame($before, $stale->fresh()->getAttributes());
    }

    public function test_changed_boundary_never_truncates_existing_evidence_or_hours(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-15 12:00:00');
        $row = $this->row($a, 15, ['regular_minutes' => 321, 'confirmed_check_out' => '17:00:00']);
        $before = $row->getAttributes();
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 0], $this->repair());
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_historical_bch_resolution_is_used_without_mutating_assignment(): void
    {
        $a = $this->assignment('2026-09-01');
        $a->update(['command_center_id' => null]);
        MachineAssignmentBchResolution::create(['machine_assignment_id' => $a->id, 'command_center_id' => $this->bch->id]);
        $row = $this->row($a, 10);
        $this->assertSame(['repaired' => 1, 'removed' => 0, 'unresolved' => 0], $this->repair());
        $this->assertSame($this->bch->id, (int) $row->fresh()->command_center_id);
        $this->assertNull($a->fresh()->command_center_id);
    }

    public function test_audit_failure_rolls_back_updates_and_deletions(): void
    {
        $a = $this->assignment('2026-09-01');
        $repair = $this->row($a, 10, ['command_center_id' => null]);
        $old = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $stale = $this->row($old, 11);
        $this->row($a, 11);
        // Trigger is confined to this isolated SQLite transaction; simulate a late write failure.
        DB::unprepared("CREATE TRIGGER reject_repair_audit BEFORE INSERT ON activity_logs WHEN NEW.event LIKE 'reconciliation.%' BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        try {
            $this->repair();
            $this->fail('Expected audit failure');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('audit failure', $exception->getMessage());
        }
        $this->assertNull($repair->fresh()->command_center_id);
        $this->assertNotNull($stale->fresh());
        $this->assertSame(0, ActivityLog::where('event', 'like', 'reconciliation.%')->count());
    }

    public function test_period_status_is_rechecked_inside_transaction_and_endpoint_requires_auth(): void
    {
        $this->post(route('reconciliation-periods.repair-links', $this->period))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->post(route('reconciliation-periods.repair-links', $this->period))->assertSessionHas('success');
        $stalePeriod = $this->period->fresh();
        $this->period->update(['status' => 'CONFIRMED']);
        $this->post(route('reconciliation-periods.repair-links', $this->period))->assertForbidden();
        $this->expectException(\RuntimeException::class);
        app(ReconciliationLinkRepairService::class)->repair($stalePeriod, null);
    }

    private function assignment(string $start, ?string $end = null, ?string $bchName = null): MachineAssignment
    {
        $bch = $bchName ? CommandCenter::create(['name' => $bchName]) : $this->bch;

        return MachineAssignment::create(['machine_id' => $this->machine->id, 'project_id' => $this->project->id,
            'command_center_id' => $bch->id, 'time_in' => $start, 'time_out' => $end]);
    }

    private function row(?MachineAssignment $assignment, int $day, array $changes = []): ReconciliationRow
    {
        return $this->period->rows()->create($changes + ['machine_id' => $this->machine->id,
            'machine_assignment_id' => $assignment?->id, 'project_id' => $assignment?->project_id,
            'command_center_id' => $assignment?->command_center_id, 'work_date' => sprintf('2026-09-%02d', $day),
            'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT'])->fresh();
    }

    private function repair(): array
    {
        return array_intersect_key(app(ReconciliationLinkRepairService::class)->repair($this->period, null),
            array_flip(['repaired', 'removed', 'unresolved']));
    }
}
