<?php

namespace Tests\Feature\Reconciliation;

use App\Exceptions\BusinessRuleException;
use App\Models\ActivityLog;
use App\Models\CommandCenter;
use App\Models\DailyPhotoCase;
use App\Models\DailyPhotoCaseEvidence;
use App\Models\DailyPhotoInterval;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineEvent;
use App\Models\OcrJob;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\MachineAssignmentTimelineService;
use App\Services\MachineService;
use App\Services\Reconciliation\DailyPhotoSyncService;
use App\Services\Reconciliation\ReconciliationExportValidator;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RetroactiveBchTransferTest extends TestCase
{
    use RefreshDatabase;

    private Machine $machine;

    private Project $project;

    private CommandCenter $a;

    private CommandCenter $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = Machine::create(['asset_code' => 'T-XL0034', 'chassis_no' => 'RETRO', 'company' => 'SGC', 'status' => 'ACTIVE']);
        $this->project = Project::create(['name' => 'Retro']);
        $this->a = CommandCenter::create(['name' => 'Cơ Hữu']);
        $this->b = CommandCenter::create(['name' => 'TĐXD 02.1']);
    }

    public function test_october_revision_propagates_july_august_september_october_and_keeps_rich_identity(): void
    {
        $old = $this->assignment('2026-07-01');
        $rows = [];
        foreach (['2026-07-14', '2026-07-15', '2026-08-15', '2026-09-20', '2026-10-20'] as $date) {
            $period = $this->period(substr($date, 0, 7));
            $rows[$date] = $this->row($period, $old, $date, $this->payload());
        }
        $case = $this->canonical($old, '2026-09-20');
        $rows['2026-09-20']->update(['daily_ocr_job_ids' => $case->evidenceMemberships()->pluck('ocr_job_id')->all(),
            'daily_intervals' => [['canonical_interval_id' => $case->intervals()->sole()->id]]]);
        $before = [];
        foreach ($rows as $date => $row) {
            $before[$date] = $row->fresh()->getAttributes();
        }
        $this->transfer('2026-09-30 23:59:59', '2026-10-01');
        $target = MachineAssignment::where('command_center_id', $this->b->id)->sole();
        $empty = $this->row($this->period('2026-09'), $target, '2026-09-20');
        $result = app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $target->id,
            '2026-07-14 23:59:59', '2026-07-15', null);
        $this->assertTrue($result['changed']);
        $this->assertCount(4, $result['propagation']['periods']);
        $this->assertSame('2026-07-14 23:59:59', $old->fresh()->time_out->toDateTimeString());
        $this->assertSame('2026-07-15 00:00:00', $target->fresh()->time_in->toDateTimeString());
        foreach ($rows as $date => $row) {
            $expected = $date < '2026-07-15' ? $old : $target;
            $this->assertSame($expected->id, (int) $row->fresh()->machine_assignment_id);
            $this->assertBusinessSame($before[$date], $row->fresh()->getAttributes());
        }
        $this->assertSame($before['2026-07-14'], $rows['2026-07-14']->fresh()->getAttributes());
        $this->assertNull($empty->fresh());
        $this->assertSame($target->id, (int) $case->fresh()->machine_assignment_id);
        $this->assertSame(2, $case->evidenceMemberships()->count());
        $this->assertSame(1, $case->intervals()->count());
        $this->assertSame(1, $this->period('2026-09')->rows()->whereDate('work_date', '2026-09-20')->count());
        $this->assertStringNotContainsString('không còn khớp phân công nguồn', app(ReconciliationExportValidator::class)->validate($this->period('2026-09'))['blocking']->implode(' '));
        $logs = ActivityLog::count();
        $this->assertFalse(app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $target->id,
            '2026-07-14 23:59:59', '2026-07-15', null)['changed']);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_revision_does_not_cross_next_transfer_or_return_and_other_machine_is_untouched(): void
    {
        $old = $this->assignment('2026-07-01', '2026-08-31 23:59:59');
        $target = $this->assignment('2026-09-01', '2026-09-14 23:59:59', $this->b);
        $c = CommandCenter::create(['name' => 'C']);
        $last = $this->assignment('2026-09-15', null, $c);
        $this->event($old, $target);
        $july = $this->row($this->period('2026-07'), $old, '2026-07-20', $this->payload());
        $september = $this->row($this->period('2026-09'), $last, '2026-09-20', $this->payload());
        $other = Machine::create(['asset_code' => 'OTHER-RETRO', 'chassis_no' => 'OTHER-RETRO', 'company' => 'SGC', 'status' => 'ACTIVE']);
        $otherAssignment = MachineAssignment::create(['machine_id' => $other->id, 'project_id' => $this->project->id, 'command_center_id' => $this->a->id, 'time_in' => '2026-07-01']);
        $otherRow = $this->row($this->period('2026-07'), $otherAssignment, '2026-07-20', $this->payload());
        $otherBefore = $otherRow->getAttributes();
        $before = $september->getAttributes();
        app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $target->id, '2026-07-14 23:59:59', '2026-07-15', null);
        $this->assertSame($target->id, (int) $july->fresh()->machine_assignment_id);
        $this->assertSame($before, $september->fresh()->getAttributes());
        $this->assertSame($otherBefore, $otherRow->fresh()->getAttributes());
        $this->assertSame('2026-09-14 23:59:59', $target->fresh()->time_out->toDateTimeString());
    }

    public function test_return_boundary_limits_retroactive_propagation_and_later_handover_stays_unchanged(): void
    {
        $old = $this->assignment('2026-07-01', '2026-08-01');
        $target = $this->assignment('2026-08-01', '2026-08-20 12:00:00', $this->b);
        $this->event($old, $target);
        MachineEvent::create(['machine_id' => $this->machine->id, 'type' => 'RETURN', 'occurred_at' => '2026-08-20 12:00:00']);
        $handover = $this->assignment('2026-09-01');
        $july = $this->row($this->period('2026-07'), $old, '2026-07-20', $this->payload());
        $later = $this->row($this->period('2026-09'), $handover, '2026-09-20', $this->payload());
        $laterBefore = $later->getAttributes();
        app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $target->id, '2026-07-14 23:59:59', '2026-07-15', null);
        $this->assertSame($target->id, (int) $july->fresh()->machine_assignment_id);
        $this->assertSame($laterBefore, $later->fresh()->getAttributes());
        $this->assertSame('2026-08-20 12:00:00', $target->fresh()->time_out->toDateTimeString());
    }

    public function test_locked_period_and_shared_canonical_relationship_are_preserved(): void
    {
        $old = $this->assignment('2026-07-01', '2026-09-30 23:59:59');
        $target = $this->assignment('2026-10-01', null, $this->b);
        $this->event($old, $target);
        $period = $this->period('2026-08');
        $row = $this->row($period, $old, '2026-08-20', $this->payload());
        $case = $this->canonical($old, '2026-08-20');
        $period->update(['status' => 'EXPORTED']);
        $before = $row->getAttributes();
        $caseBefore = $case->fresh()->getAttributes();
        $result = app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $target->id, '2026-07-14 23:59:59', '2026-07-15', null);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame($caseBefore, $case->fresh()->getAttributes());
        $this->assertSame('PROTECTED_PERIOD', $result['propagation']['protected_periods'][0]['reason']);
    }

    public static function mergeMatrix(): array
    {
        return [
            'rich source empty target' => [['work_content' => 'Source', 'regular_minutes' => 321], [], 'source'],
            'empty source rich target' => [[], ['work_content' => 'Target', 'regular_minutes' => 321], 'target'],
            'identical' => [['work_content' => 'Same', 'daily_ocr_job_ids' => [2, 1]], ['work_content' => 'Same', 'daily_ocr_job_ids' => [1, 2]], 'target'],
            'complementary' => [['regular_minutes' => 321], ['work_content' => 'Target'], 'review'],
            'conflicting hours' => [['regular_minutes' => 321], ['regular_minutes' => 123], 'review'],
            'reviewed target' => [['work_content' => 'Source'], ['status' => 'REVIEWED'], 'protected'],
            'manual target' => [['work_content' => 'Source'], ['manually_edited_at' => '2026-09-20 12:00:00'], 'protected'],
        ];
    }

    #[DataProvider('mergeMatrix')]
    public function test_duplicate_merge_policy_preserves_payload_and_identity(array $sourcePayload, array $targetPayload, string $expected): void
    {
        $old = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $target = $this->assignment('2026-09-01', null, $this->b);
        $period = $this->period('2026-09');
        // Insert target first to exercise ordering independently of IDs.
        $targetRow = $this->row($period, $target, '2026-09-20', $targetPayload);
        $sourceRow = $this->row($period, $old, '2026-09-20', $sourcePayload);
        $before = [$sourceRow->getAttributes(), $targetRow->getAttributes()];
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        if ($expected === 'source') {
            $this->assertNull($targetRow->fresh());
            $this->assertBusinessSame($before[0], $sourceRow->fresh()->getAttributes());
            $this->assertSame($target->id, (int) $sourceRow->fresh()->machine_assignment_id);
        } elseif ($expected === 'target') {
            $this->assertNull($sourceRow->fresh());
            $this->assertSame($before[1], $targetRow->fresh()->getAttributes());
        } else {
            $this->assertSame($before, [$sourceRow->fresh()->getAttributes(), $targetRow->fresh()->getAttributes()]);
            $this->assertArrayHasKey($expected === 'protected' ? 'PROTECTED_DUPLICATE' : 'DUPLICATE_PAYLOAD_CONFLICT', $result['diagnostics']['reasons']);
        }
        $this->assertSame(2, $result['diagnostics']['total_inspected']);
        $this->assertSame($result['diagnostics']['total_inspected'], $result['repaired'] + $result['removed'] + $result['unresolved'] + $result['diagnostics']['already_correct']);
        $logs = ActivityLog::count();
        $again = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(0, $again['repaired']);
        $this->assertSame(0, $again['removed']);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_canonical_and_empty_target_relink_preserves_photos_pairing_gps_and_manual_content(): void
    {
        $old = $this->assignment('2026-07-01', '2026-08-31 23:59:59');
        $target = $this->assignment('2026-09-01', null, $this->b);
        $period = $this->period('2026-09');
        $case = $this->canonical($old, '2026-09-20');
        $row = $this->row($period, $old, '2026-09-20', $this->payload() + ['daily_intervals' => [['canonical_interval_id' => $case->intervals()->sole()->id]]]);
        $empty = $this->row($period, $target, '2026-09-20');
        $snapshots = [];
        foreach (['daily_photo_case_evidence', 'daily_photo_intervals', 'zalo_attachments', 'zalo_messages'] as $table) {
            $snapshots[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $before = $row->getAttributes();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(1, $result['repaired']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertNull($empty->fresh());
        $this->assertBusinessSame($before, $row->fresh()->getAttributes());
        $this->assertSame($target->id, (int) $case->fresh()->machine_assignment_id);
        $this->assertSame('assignment:'.$target->id.'|date:2026-09-20', $case->fresh()->scope_key);
        foreach ($snapshots as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson());
        }
        foreach ($case->ocrJobs()->get() as $job) {
            $this->assertSame($target->id, $job->daily_metadata['case_materialization']['machine_assignment_id']);
            $this->assertSame('COMPLETED', $job->status);
        }
        $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['repaired']);
    }

    public function test_two_populated_canonical_selections_are_not_merged(): void
    {
        $old = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $target = $this->assignment('2026-09-01', null, $this->b);
        $period = $this->period('2026-09');
        $this->canonical($old, '2026-09-20');
        $this->canonical($target, '2026-09-20');
        $row = $this->row($period, $old, '2026-09-20', $this->payload());
        $this->row($period, $target, '2026-09-20', ['work_content' => 'Different']);
        $before = $row->getAttributes();
        $this->assertArrayHasKey('CANONICAL_CONFLICT', app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(4, DailyPhotoCaseEvidence::count());
        $this->assertSame(2, DailyPhotoInterval::count());
    }

    public function test_transfer_and_canonical_audit_failure_roll_back_the_entire_operation(): void
    {
        $old = $this->assignment('2026-09-01');
        $period = $this->period('2026-09');
        $row = $this->row($period, $old, '2026-09-20', $this->payload());
        $case = $this->canonical($old, '2026-09-20');
        $before = $row->getAttributes();
        DB::unprepared("CREATE TRIGGER reject_canonical_audit BEFORE INSERT ON activity_logs WHEN NEW.event = 'reconciliation.canonical_relinked' BEGIN SELECT RAISE(ABORT, 'canonical audit failure'); END");
        try {
            $this->transfer('2026-09-14 23:59:59', '2026-09-15');
            $this->fail('Expected failure');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('canonical audit failure', $e->getMessage());
        }
        $this->assertNull($old->fresh()->time_out);
        $this->assertSame(1, MachineAssignment::count());
        $this->assertSame(0, MachineEvent::where('type', 'TRANSFER')->count());
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame($old->id, (int) $case->fresh()->machine_assignment_id);
    }

    public function test_future_transfer_is_idempotent_validated_and_append_keeps_existing_row_ids(): void
    {
        $old = $this->assignment('2026-09-01');
        $period = $this->period('2026-09');
        foreach (range(1, 30) as $day) {
            $rows[$day] = $this->row($period, $old, sprintf('2026-09-%02d', $day), $this->payload());
        }
        $this->transfer('2026-09-14 23:59:59', '2026-09-15');
        $ids = $period->rows()->pluck('id')->all();
        app(ReconciliationPeriodService::class)->syncMonthly($period);
        $this->assertSame($ids, $period->rows()->pluck('id')->all());
        $this->assertSame(30, $period->rows()->count());
        foreach ($rows as $day => $row) {
            $this->assertSame($day < 15 ? $this->a->id : $this->b->id, (int) $row->fresh()->command_center_id);
        }
        $logs = ActivityLog::count();
        $this->transfer('2026-09-14 23:59:59', '2026-09-15');
        $this->assertSame($logs, ActivityLog::count());
        $this->assertSame(2, MachineAssignment::count());
    }

    public function test_invalid_transfer_fails_before_timeline_or_content_changes(): void
    {
        $old = $this->assignment('2026-09-01');
        try {
            $this->transfer('2026-09-20', '2026-09-15');
            $this->fail('Expected invalid boundary');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Giờ ra', $e->getMessage());
        }
        $this->assertNull($old->fresh()->time_out);
        $this->assertSame(1, MachineAssignment::count());
    }

    public function test_canonical_without_any_period_relinks_on_transfer_without_repairing_pairing(): void
    {
        $old = $this->assignment('2026-09-01');
        $case = $this->canonical($old, '2026-09-20');
        $interval = $case->intervals()->sole()->getAttributes();
        $this->transfer('2026-09-14 23:59:59', '2026-09-15');
        $this->assertSame($this->b->id, (int) $case->fresh()->machineAssignment->command_center_id);
        $this->assertSame($interval, $case->intervals()->sole()->getAttributes());
    }

    public function test_historical_boundary_endpoint_requires_auth_and_machine_ownership(): void
    {
        $old = $this->assignment('2026-07-01', '2026-09-30 23:59:59');
        $target = $this->assignment('2026-10-01', null, $this->b);
        $this->event($old, $target);
        $url = route('ops.transfer.revise', [$this->machine, $target]);
        $data = ['time_out' => '2026-07-14 23:59:59', 'time_in' => '2026-07-15'];
        $this->patch($url, $data)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->from(route('machines.show', $this->machine))->patch($url, $data)->assertSessionHas('success');
        $this->get(route('machines.show', $this->machine))->assertOk()->assertSee('Sửa mốc điều chuyển hồi tố');
        $other = Machine::create(['asset_code' => 'OTHER', 'chassis_no' => 'OTHER', 'company' => 'SGC', 'status' => 'ACTIVE']);
        $this->patch(route('ops.transfer.revise', [$other, $target]), $data)->assertNotFound();
    }

    public function test_same_day_segments_relink_canonical_without_repairing_pairing_or_expanding_rows(): void
    {
        $old = $this->assignment('2026-09-01');
        $period = $this->period('2026-09');
        $case = $this->canonical($old, '2026-09-15');
        $row = $this->row($period, $old, '2026-09-15', $this->payload() + ['segment_start' => '07:00:00', 'segment_end' => '12:00:00']);
        $before = $row->getAttributes();
        $this->transfer('2026-09-15 07:00:00', '2026-09-15 07:00:00');
        $this->assertSame($this->b->id, (int) $row->fresh()->command_center_id);
        $this->assertBusinessSame($before, $row->fresh()->getAttributes());
        $this->assertSame($row->fresh()->machine_assignment_id, $case->fresh()->machine_assignment_id);
        $this->assertSame(2, DailyPhotoCaseEvidence::count());
        $this->assertSame(1, DailyPhotoInterval::count());
        $this->assertSame(0, ActivityLog::where('event', 'reconciliation.propagation_review')->count());
    }

    public function test_empty_canonical_target_is_removed_but_source_case_identity_and_pairing_survive(): void
    {
        $old = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $target = $this->assignment('2026-09-01', null, $this->b);
        $case = $this->canonical($old, '2026-09-20');
        $empty = DailyPhotoCase::create(['scope_key' => 'assignment:'.$target->id.'|date:2026-09-20',
            'machine_id' => $this->machine->id, 'machine_assignment_id' => $target->id, 'work_date' => '2026-09-20', 'source_version' => 'v1']);
        $this->row($this->period('2026-09'), $old, '2026-09-20', $this->payload());
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($this->period('2026-09'), null)['repaired']);
        $this->assertNull($empty->fresh());
        $this->assertSame($target->id, (int) $case->fresh()->machine_assignment_id);
        $this->assertSame(2, DailyPhotoCaseEvidence::count());
        $this->assertSame(1, DailyPhotoInterval::count());
    }

    public function test_same_day_capture_before_physical_in_moves_without_time_loss(): void
    {
        $old = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $target = $this->assignment('2026-09-20 12:00:00', null, $this->b);
        $case = $this->canonical($old, '2026-09-20');
        $row = $this->row($this->period('2026-09'), $old, '2026-09-20', ['segment_start' => '12:00:00', 'segment_end' => '23:59:59']);
        $before = $row->getAttributes();
        $result = app(ReconciliationLinkRepairService::class)->repair($this->period('2026-09'), null);
        $this->assertSame([], $result['diagnostics']['reasons']);
        $this->assertSame(1, $result['repaired']);
        $this->assertBusinessSame($before, $row->fresh()->getAttributes());
        $this->assertSame($target->id, (int) $case->fresh()->machine_assignment_id);
    }

    public function test_handover_and_return_propagate_only_valid_history_and_preserve_gap_content(): void
    {
        $this->machine->update(['status' => 'WAIT_HANDOVER']);
        $period = $this->period('2026-09');
        $orphan = $period->rows()->create($this->payload() + ['machine_id' => $this->machine->id,
            'work_date' => '2026-09-20', 'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT'])->fresh();
        $before = $orphan->getAttributes();
        app(MachineService::class)->handoverToProject($this->machine->id, $this->project->id, $this->b->id, '2026-09-15', 'proof.pdf');
        $this->assertSame($this->b->id, (int) $orphan->fresh()->command_center_id);
        $this->assertBusinessSame($before, $orphan->fresh()->getAttributes());
        app(MachineService::class)->returnToCompany($this->machine->id, '2026-09-18 23:59:59', 'return.pdf', true);
        $returned = $orphan->fresh()->getAttributes();
        $this->assertBusinessSame($before, $returned);
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame([], $result['diagnostics']['reasons']);
        $this->assertSame(0, $result['normalized_unassigned']);
        $this->assertNull($orphan->fresh()->machine_assignment_id);
        $this->assertNull($orphan->fresh()->command_center_id);
        $this->assertSame($returned, $orphan->fresh()->getAttributes());
        $this->assertSame(1, MachineAssignment::count());
    }

    public function test_historical_revision_rejects_overlap_and_lifecycle_boundary_without_mutation(): void
    {
        $old = $this->assignment('2026-07-01', '2026-08-01');
        $target = $this->assignment('2026-08-01', '2026-08-20', $this->b);
        $this->event($old, $target);
        $this->assignment('2026-09-01');
        try {
            app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $target->id, '2026-09-10', '2026-09-10', null);
            $this->fail('Expected overlap rejection');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('chồng lấn', $e->getMessage());
        }
        $this->assertSame('2026-08-01 00:00:00', $old->fresh()->time_out->toDateTimeString());
        MachineEvent::create(['machine_id' => $this->machine->id, 'type' => 'RETURN', 'occurred_at' => '2026-08-01']);
        try {
            app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $target->id, '2026-07-14', '2026-07-15', null);
            $this->fail('Expected lifecycle rejection');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('boundary', $e->getMessage());
        }
        $this->assertSame('2026-08-01 00:00:00', $target->fresh()->time_in->toDateTimeString());
    }

    public function test_mid_write_failure_restores_deleted_duplicate_canonical_and_row(): void
    {
        $old = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $target = $this->assignment('2026-09-01', null, $this->b);
        $period = $this->period('2026-09');
        $row = $this->row($period, $old, '2026-09-20', $this->payload());
        $empty = $this->row($period, $target, '2026-09-20');
        $case = $this->canonical($old, '2026-09-20');
        $before = $row->getAttributes();
        DB::unprepared("CREATE TRIGGER reject_row_relink BEFORE UPDATE ON reconciliation_rows BEGIN SELECT RAISE(ABORT, 'row write failure'); END");
        try {
            app(ReconciliationLinkRepairService::class)->repair($period, null);
            $this->fail('Expected write failure');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('row write failure', $e->getMessage());
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertNotNull($empty->fresh());
        $this->assertSame($old->id, (int) $case->fresh()->machine_assignment_id);
        $this->assertSame(0, ActivityLog::where('event', 'like', 'reconciliation.%')->count());
    }

    public function test_daily_photo_materialization_cannot_append_overlapping_stale_identity(): void
    {
        config(['daily_photos.enabled' => true]);
        $old = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $target = $this->assignment('2026-09-01', null, $this->b);
        $period = $this->period('2026-09');
        $row = $this->row($period, $old, '2026-09-20', $this->payload());
        $this->canonical($target, '2026-09-20');
        app(DailyPhotoSyncService::class)->sync($period);
        $this->assertSame([$row->id], $period->rows()->pluck('id')->all());
        $this->assertSame(210, (int) $row->fresh()->regular_minutes);
        $this->assertSame('HUMAN', $row->fresh()->work_content);
    }

    public function test_canonical_shared_with_locked_weekly_period_blocks_monthly_relink(): void
    {
        $old = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $this->assignment('2026-09-01', null, $this->b);
        $case = $this->canonical($old, '2026-09-20');
        $monthly = $this->period('2026-09');
        $row = $this->row($monthly, $old, '2026-09-20', $this->payload());
        $weekly = ReconciliationPeriod::create(['name' => 'Locked weekly', 'type' => 'WEEKLY', 'date_from' => '2026-09-14', 'date_to' => '2026-09-20', 'status' => 'CONFIRMED']);
        $this->row($weekly, $old, '2026-09-20');
        $before = $row->getAttributes();
        $result = app(ReconciliationLinkRepairService::class)->repair($monthly, null);
        $this->assertSame(['PROTECTED_CANONICAL_RELATIONSHIP' => 1], $result['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame($old->id, (int) $case->fresh()->machine_assignment_id);
    }

    public function test_batch_handover_rechecks_history_and_propagates_existing_orphan(): void
    {
        $this->actingAs(User::factory()->create());
        $this->machine->update(['status' => 'RETURNED']);
        $old = $this->assignment('2026-08-01', '2026-09-18');
        $row = $this->row($this->period('2026-09'), $old, '2026-09-20', $this->payload());
        $data = ['machine_ids' => [$this->machine->id], 'project_id' => $this->project->id,
            'command_center_id' => $this->b->id, 'time_in' => '2026-09-15'];
        $this->post(route('machines.batch.handover'), $data)->assertSessionHasErrors('error');
        $this->assertSame(1, MachineAssignment::count());
        $data['time_in'] = '2026-09-19';
        $this->post(route('machines.batch.handover'), $data)->assertSessionHas('success');
        $this->assertSame($this->b->id, (int) $row->fresh()->command_center_id);
        $this->assertSame(210, (int) $row->fresh()->regular_minutes);
    }

    public function test_retroactive_propagation_uses_final_owner_for_entire_boundary_day(): void
    {
        $old = $this->assignment('2026-07-01', '2026-08-01');
        $target = $this->assignment('2026-08-01', '2026-09-15 07:00:00', $this->b);
        $this->event($old, $target);
        $c = $this->assignment('2026-09-15 07:00:00');
        // Stale relationship in the later lifecycle must remain for separate recovery.
        $later = $this->row($this->period('2026-09'), $old, '2026-09-15', $this->payload() + ['segment_start' => '07:00:00', 'segment_end' => '23:59:59']);
        $before = $later->getAttributes();
        app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $target->id, '2026-07-14', '2026-07-15', null);
        $this->assertBusinessSame($before, $later->fresh()->getAttributes());
        $this->assertSame($c->id, $later->fresh()->machine_assignment_id);
        $this->assertSame('2026-09-15 07:00:00', $c->fresh()->time_in->toDateTimeString());
    }

    private function payload(): array
    {
        return ['work_content' => 'HUMAN', 'work_location' => 'Location', 'notes' => 'Keep',
            'regular_minutes' => 210, 'regular_morning_start' => '07:30:00', 'regular_morning_end' => '11:00:00',
            'ot_afternoon_minutes' => 30, 'gps_check_in' => '07:25:00', 'gps_check_out' => '11:05:00',
            'gps_check_in_diff_minutes' => 5, 'journal_row_ids' => [21], 'ai_reconciliation_job_id' => 31];
    }

    private function assignment(string $start, ?string $end = null, ?CommandCenter $bch = null): MachineAssignment
    {
        return MachineAssignment::create(['machine_id' => $this->machine->id, 'project_id' => $this->project->id,
            'command_center_id' => ($bch ?? $this->a)->id, 'time_in' => $start, 'time_out' => $end]);
    }

    private function period(string $month): ReconciliationPeriod
    {
        $p = app(ReconciliationPeriodService::class)->ensureMonthly($month);
        if ($p->status === 'DRAFT') {
            $p->update(['status' => 'GENERATED']);
        }

        return $p;
    }

    private function row(ReconciliationPeriod $p, MachineAssignment $a, string $date, array $fields = []): ReconciliationRow
    {
        return $p->rows()->create($fields + ['machine_id' => $a->machine_id, 'machine_assignment_id' => $a->id, 'project_id' => $a->project_id,
            'command_center_id' => $a->command_center_id, 'work_date' => $date, 'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT'])->fresh();
    }

    private function transfer(string $out, string $in): void
    {
        app(MachineService::class)->transferAssignment($this->machine->id, $this->project->id, $this->a->id, $this->project->id, $this->b->id, $out, $in, null);
    }

    private function event(MachineAssignment $old, MachineAssignment $target): void
    {
        MachineEvent::create(['machine_id' => $this->machine->id, 'type' => 'TRANSFER', 'occurred_at' => $target->time_in,
            'from_project_id' => $old->project_id, 'from_command_center_id' => $old->command_center_id,
            'to_project_id' => $target->project_id, 'to_command_center_id' => $target->command_center_id]);
    }

    private function assertBusinessSame(array $before, array $after): void
    {
        foreach (['machine_assignment_id', 'project_id', 'command_center_id', 'segment_start', 'segment_end', 'updated_at'] as $key) {
            unset($before[$key], $after[$key]);
        }
        $this->assertSame($before, $after);
    }

    private function canonical(MachineAssignment $assignment, string $date): DailyPhotoCase
    {
        $case = DailyPhotoCase::create(['scope_key' => 'assignment:'.$assignment->id.'|date:'.$date, 'machine_id' => $this->machine->id,
            'machine_assignment_id' => $assignment->id, 'work_date' => $date, 'status' => 'READY', 'source_version' => 'v1']);
        $members = [];
        foreach (['07:30:00', '11:00:00'] as $i => $time) {
            $message = ZaloMessage::create(['group_id' => 'retro', 'message_id' => 'retro-'.$case->id.'-'.$i, 'sender_id' => 'retro',
                'sent_at' => $date.' '.$time, 'received_at' => $date.' '.$time, 'status' => 'STORED']);
            $attachment = ZaloAttachment::create(['zalo_message_id' => $message->id, 'attachment_index' => 0, 'storage_disk' => 'local',
                'storage_path' => 'test/retro-'.$case->id.'-'.$i.'.jpg', 'sha256' => hash('sha256', 'retro-'.$case->id.'-'.$i), 'mime_type' => 'image/jpeg', 'byte_size' => 10, 'status' => 'STORED']);
            $job = OcrJob::create(['zalo_attachment_id' => $attachment->id, 'document_type' => 'DAILY_TIMEMARK', 'status' => 'COMPLETED',
                'machine_id' => $this->machine->id, 'extracted_date' => $date, 'extracted_time' => $time, 'daily_photo_case_id' => $case->id,
                'daily_metadata' => ['case_materialization' => ['machine_assignment_id' => $assignment->id, 'scope_key' => $case->scope_key], 'ocr_content' => 'unchanged']]);
            $members[] = DailyPhotoCaseEvidence::create(['daily_photo_case_id' => $case->id, 'ocr_job_id' => $job->id,
                'capture_datetime' => $date.' '.$time, 'pairing_state' => 'PAIRED']);
        }
        DailyPhotoInterval::create(['daily_photo_case_id' => $case->id, 'sequence' => 1, 'start_evidence_id' => $members[0]->id, 'end_evidence_id' => $members[1]->id,
            'raw_start_at' => $date.' 07:30:00', 'raw_end_at' => $date.' 11:00:00', 'raw_start_time' => '07:30:00', 'raw_end_time' => '11:00:00', 'pairing_policy_version' => 'v1']);

        return $case;
    }
}
