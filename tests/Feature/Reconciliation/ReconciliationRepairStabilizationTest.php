<?php

namespace Tests\Feature\Reconciliation;

use App\Models\ActivityLog;
use App\Models\CommandCenter;
use App\Models\DailyPhotoCase;
use App\Models\DailyPhotoCaseEvidence;
use App\Models\DailyPhotoInterval;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineAssignmentBchResolution;
use App\Models\OcrJob;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\Reconciliation\ReconciliationExportValidator;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReconciliationRepairStabilizationTest extends TestCase
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
        $this->machine = Machine::create(['asset_code' => 'STABILIZE', 'chassis_no' => 'STABILIZE', 'company' => 'SGC', 'status' => 'ACTIVE']);
        $this->project = Project::create(['name' => 'A']);
        $this->bch = CommandCenter::create(['name' => 'A']);
    }

    public static function payloads(): array
    {
        return [
            'regular minutes' => [['regular_minutes' => 321]],
            'overtime' => [['lunch_minutes' => 15, 'ot_afternoon_minutes' => 60, 'ot_evening_minutes' => 45]],
            'OCR' => [['daily_ocr_job_ids' => [12, 13], 'ocr_check_in_raw' => '07:30:00', 'ocr_check_out_raw' => '11:00:00']],
            'journal and AI' => [['journal_row_ids' => [21], 'ai_reconciliation_job_id' => 31]],
            'work content' => [['work_content' => 'Thi công nền', 'work_location' => 'A']],
            'manual content' => [['work_content' => 'HUMAN', 'manually_edited_at' => '2026-09-25 10:00:00', 'notes' => 'Nhập tay']],
            'manual timestamp only' => [['manually_edited_at' => '2026-09-25 10:00:00']],
            'Daily Photo pairing' => [['daily_ocr_job_ids' => [12, 13], 'daily_intervals' => [['start_job_id' => 12, 'end_job_id' => 13, 'start' => '07:30:00', 'end' => '11:00:00']], 'evidence_signature' => str_repeat('a', 64), 'evidence_status' => 'COMPLETE']],
            'allocated time' => [['regular_morning_start' => '07:30:00', 'regular_morning_end' => '11:00:00', 'regular_minutes' => 210]],
        ];
    }

    #[DataProvider('payloads')]
    public function test_deterministic_stale_link_preserves_full_payload_and_audits_only_metadata(array $payload): void
    {
        $old = $this->assignment('2026-09-01', '2026-09-14 23:59:59');
        $target = $this->assignment('2026-09-15');
        $row = $this->row($old, '2026-09-20', $payload);
        $before = $row->getAttributes();
        $result = $this->repair();
        $this->assertSame(1, $result['repaired']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(1, $result['diagnostics']['repairable_stale_links']);
        $after = $row->fresh()->getAttributes();
        $this->assertSame($target->id, (int) $after['machine_assignment_id']);
        foreach (['machine_assignment_id', 'project_id', 'command_center_id', 'updated_at'] as $field) {
            unset($before[$field], $after[$field]);
        }
        $this->assertSame($before, $after);
        $audit = ActivityLog::where('event', 'reconciliation.links_repaired')->sole();
        $this->assertSame(['machine_assignment_id'], array_keys($audit->properties['new']));
        $snapshot = $row->fresh()->getAttributes();
        $rerun = $this->repair();
        $this->assertSame(0, $rerun['repaired']);
        $this->assertSame(1, $rerun['diagnostics']['already_correct']);
        $this->assertSame($snapshot, $row->fresh()->getAttributes());
        $this->assertSame(1, ActivityLog::where('event', 'reconciliation.links_repaired')->count());
        $this->assertStringNotContainsString('không còn khớp phân công nguồn', app(ReconciliationExportValidator::class)->validate($this->period)['blocking']->implode(' '));
    }

    public function test_same_day_orphans_and_stale_same_date_sources_resolve_by_segment(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-15 11:30:00');
        $b = $this->assignment('2026-09-15 13:30:00');
        $morning = $this->row(null, '2026-09-15', ['segment_start' => '07:30:00', 'segment_end' => '11:30:00', 'work_content' => 'Morning']);
        $afternoon = $this->row($a, '2026-09-15', ['segment_start' => '13:30:00', 'segment_end' => '17:00:00', 'work_content' => 'Afternoon']);
        $result = $this->repair();
        $this->assertSame(2, $result['repaired']);
        $this->assertSame($a->id, (int) $morning->fresh()->machine_assignment_id);
        $this->assertSame($b->id, (int) $afternoon->fresh()->machine_assignment_id);
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($this->period)['can_export']);
    }

    public function test_empty_same_day_stale_source_relinks_instead_of_narrowing_to_an_empty_segment(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-15 11:30:00');
        $b = $this->assignment('2026-09-15 13:30:00');
        $row = $this->row($a, '2026-09-15', ['segment_start' => '13:30:00', 'segment_end' => '17:00:00']);
        $this->assertSame(1, $this->repair()['repaired']);
        $this->assertSame($b->id, (int) $row->fresh()->machine_assignment_id);
        $this->assertSame('13:30:00', $row->fresh()->segment_start);
        $this->assertSame('17:00:00', $row->fresh()->segment_end);
    }

    public function test_whole_period_diagnostics_classify_every_row_and_endpoint_exposes_reasons(): void
    {
        $a = $this->assignment('2026-09-05', '2026-09-10 23:59:59');
        $missingBch = $this->assignment('2026-09-11', '2026-09-12 23:59:59', ['command_center_id' => null]);
        $overlap = $this->assignment('2026-09-15', '2026-09-20 23:59:59');
        $this->assignment('2026-09-16', '2026-09-19 23:59:59');
        $this->row($a, '2026-09-06');
        $this->row(null, '2026-09-01', ['work_content' => 'No assignment']);
        $this->row($a, '2026-09-07', ['status' => 'REVIEWED', 'command_center_id' => null]);
        $this->row($missingBch, '2026-09-11');
        $this->row($overlap, '2026-09-17');
        $this->row($a, '2026-09-08', ['segment_start' => '17:00:00', 'segment_end' => '07:00:00']);
        $result = $this->repair();
        $this->assertSame(6, $result['diagnostics']['total_inspected']);
        $this->assertSame(1, $result['diagnostics']['already_correct']);
        $this->assertSame(5, $result['unresolved']);
        $this->assertEquals(['NO_EFFECTIVE_ASSIGNMENT' => 1, 'PROTECTED_RELATIONSHIP' => 1,
            'NO_BCH_RESOLUTION' => 1, 'TRUE_ASSIGNMENT_OVERLAP' => 1, 'INVALID_SEGMENT' => 1], $result['diagnostics']['reasons']);
        $this->assertCount(5, $result['diagnostics']['rows']);
        $this->assertStringContainsString('phân công nguồn thực sự chồng lấn', app(ReconciliationExportValidator::class)->validate($this->period)['warnings']->implode(' '));
        $this->assertSame(0, ActivityLog::where('event', 'reconciliation.links_repaired')->count());
        $this->actingAs(User::factory()->create())->from(route('reconciliation-periods.show', $this->period))
            ->post(route('reconciliation-periods.repair-links', $this->period))->assertSessionHas('repair_diagnostics');
        $this->get(route('reconciliation-periods.show', $this->period))->assertOk()->assertSee('NO_BCH_RESOLUTION')->assertSee('Các dòng cần kiểm tra');
    }

    public function test_duplicate_target_does_not_merge_distinct_data(): void
    {
        $old = $this->assignment('2026-08-01', '2026-08-31 23:59:59');
        $target = $this->assignment('2026-09-01');
        $stale = $this->row($old, '2026-09-10', ['work_content' => 'Stale', 'regular_minutes' => 123]);
        $this->row($target, '2026-09-10', ['work_content' => 'Target', 'regular_minutes' => 321]);
        $before = $stale->getAttributes();
        $this->assertSame(['TARGET_DUPLICATE' => 1], $this->repair()['diagnostics']['reasons']);
        $this->assertSame($before, $stale->fresh()->getAttributes());
        $this->assertSame(2, $this->period->rows()->count());
    }

    public function test_multiple_orphans_cannot_claim_the_same_target(): void
    {
        $this->assignment('2026-09-01');
        $this->row(null, '2026-09-10', ['segment_start' => '07:00:00', 'segment_end' => '11:00:00', 'work_content' => 'One']);
        $this->row(null, '2026-09-10', ['segment_start' => '13:00:00', 'segment_end' => '17:00:00', 'work_content' => 'Two']);
        $result = $this->repair();
        $this->assertSame(1, $result['repaired']);
        $this->assertSame(['TARGET_DUPLICATE' => 1], $result['diagnostics']['reasons']);
        $this->assertSame(0, $this->repair()['repaired']);
    }

    public function test_invalid_assignment_and_partial_segment_fail_closed_in_repair_and_validator(): void
    {
        $invalid = $this->assignment('2026-09-10', '2026-09-09');
        $row = $this->row($invalid, '2026-09-10', ['regular_minutes' => 123]);
        $before = $row->getAttributes();
        $this->assertSame(['INVALID_TIMELINE' => 1], $this->repair()['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertFalse(app(ReconciliationExportValidator::class)->validate($this->period)['can_export']);
    }

    public function test_return_handover_gap_never_truncates_data_or_invents_assignment(): void
    {
        $a = $this->assignment('2026-09-05 07:30:00', '2026-09-10 11:30:00');
        $this->row(null, '2026-09-04', ['work_content' => 'Before handover']);
        $boundary = $this->row($a, '2026-09-10', ['regular_minutes' => 321]);
        $this->row($a, '2026-09-11', ['work_content' => 'After return']);
        $before = $boundary->getAttributes();
        $this->assertEquals(['NO_EFFECTIVE_ASSIGNMENT' => 2, 'SEGMENT_AMBIGUITY' => 1], $this->repair()['diagnostics']['reasons']);
        $this->assertSame($before, $boundary->fresh()->getAttributes());
    }

    public function test_historical_resolution_is_assignment_scoped_and_unique_by_schema(): void
    {
        $old = $this->assignment('2026-09-01', '2026-09-14 23:59:59');
        $b = CommandCenter::create(['name' => 'Historical B']);
        $target = $this->assignment('2026-09-15', null, ['command_center_id' => null]);
        MachineAssignmentBchResolution::create(['machine_assignment_id' => $target->id, 'command_center_id' => $b->id]);
        $row = $this->row($old, '2026-09-20', ['daily_ocr_job_ids' => [1]]);
        $this->assertSame(1, $this->repair()['repaired']);
        $this->assertSame($b->id, (int) $row->fresh()->command_center_id);
        $this->assertNull($target->fresh()->command_center_id);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        MachineAssignmentBchResolution::create(['machine_assignment_id' => $target->id, 'command_center_id' => $this->bch->id]);
    }

    public function test_canonical_interval_identity_is_preserved_with_explicit_diagnostic(): void
    {
        $old = $this->assignment('2026-09-01', '2026-09-14 23:59:59');
        $this->assignment('2026-09-15');
        $row = $this->row($old, '2026-09-20', ['daily_intervals' => [['canonical_interval_id' => 99]], 'regular_minutes' => 321]);
        $before = $row->getAttributes();
        $this->assertSame(['CANONICAL_RELATIONSHIP' => 1], $this->repair()['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_real_canonical_membership_pairing_and_images_remain_byte_for_byte_unchanged(): void
    {
        $old = $this->assignment('2026-09-01');
        $case = DailyPhotoCase::create(['scope_key' => 'stabilization-canonical', 'machine_id' => $this->machine->id,
            'machine_assignment_id' => $old->id, 'work_date' => '2026-09-20', 'source_version' => 'canonical-v1', 'status' => 'READY']);
        $members = [];
        foreach (['07:30:00', '11:00:00'] as $i => $time) {
            $message = ZaloMessage::create(['group_id' => 'repair', 'message_id' => 'repair-'.$i, 'sender_id' => 'repair',
                'sent_at' => '2026-09-20 '.$time, 'received_at' => '2026-09-20 '.$time, 'status' => 'STORED']);
            $attachment = ZaloAttachment::create(['zalo_message_id' => $message->id, 'attachment_index' => 0,
                'storage_disk' => 'local', 'storage_path' => 'test/repair-'.$i.'.jpg', 'sha256' => hash('sha256', 'repair-'.$i),
                'mime_type' => 'image/jpeg', 'byte_size' => 10, 'status' => 'STORED']);
            $job = OcrJob::create(['zalo_attachment_id' => $attachment->id, 'document_type' => 'DAILY_TIMEMARK',
                'status' => 'COMPLETED', 'machine_id' => $this->machine->id, 'daily_photo_case_id' => $case->id]);
            $members[] = DailyPhotoCaseEvidence::create(['daily_photo_case_id' => $case->id, 'ocr_job_id' => $job->id,
                'capture_datetime' => '2026-09-20 '.$time, 'pairing_state' => 'PAIRED']);
        }
        DailyPhotoInterval::create(['daily_photo_case_id' => $case->id, 'sequence' => 1,
            'start_evidence_id' => $members[0]->id, 'end_evidence_id' => $members[1]->id,
            'raw_start_at' => '2026-09-20 07:30:00', 'raw_end_at' => '2026-09-20 11:00:00',
            'raw_start_time' => '07:30:00', 'raw_end_time' => '11:00:00', 'pairing_policy_version' => 'v1']);
        // Membership itself protects identity even if the row has no interval IDs yet.
        $row = $this->row($old, '2026-09-20', ['daily_ocr_job_ids' => array_map(fn ($member) => $member->ocr_job_id, $members)]);
        $old->update(['time_out' => '2026-09-14 23:59:59']);
        $this->assignment('2026-09-15');
        $tables = ['daily_photo_cases', 'daily_photo_case_evidence', 'daily_photo_intervals', 'ocr_jobs', 'zalo_attachments', 'zalo_messages'];
        foreach ($tables as $table) {
            $snapshots[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $before = $row->getAttributes();
        $this->assertSame(['CANONICAL_RELATIONSHIP' => 1], $this->repair()['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        foreach ($snapshots as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }

    public function test_a_to_b_to_c_repairs_content_rows_using_historical_dates(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-10 23:59:59');
        $projectB = Project::create(['name' => 'B']);
        $bchB = CommandCenter::create(['name' => 'B']);
        $b = $this->assignment('2026-09-11', '2026-09-20 23:59:59', ['project_id' => $projectB->id, 'command_center_id' => $bchB->id]);
        $bchC = CommandCenter::create(['name' => 'C']);
        $c = $this->assignment('2026-09-21', null, ['command_center_id' => $bchC->id]);
        foreach (range(1, 30) as $day) {
            $this->row($a, sprintf('2026-09-%02d', $day), ['work_content' => 'Day '.$day, 'regular_minutes' => 321]);
        }
        $result = $this->repair();
        $this->assertSame(20, $result['repaired']);
        $this->assertSame(10, $result['diagnostics']['already_correct']);
        foreach ($this->period->rows()->get() as $row) {
            $expected = $row->work_date->day <= 10 ? $a : ($row->work_date->day <= 20 ? $b : $c);
            $this->assertSame($expected->id, (int) $row->machine_assignment_id);
            $this->assertSame($expected->project_id, (int) $row->project_id);
            $this->assertSame($expected->command_center_id, (int) $row->command_center_id);
            $this->assertSame('Day '.$row->work_date->day, $row->work_content);
            $this->assertSame(321, (int) $row->regular_minutes);
        }
        $this->assertSame(0, $this->repair()['repaired']);
    }

    public function test_stale_confirmed_and_review_timestamp_relationships_remain_locked(): void
    {
        $old = $this->assignment('2026-09-01', '2026-09-14 23:59:59');
        $this->assignment('2026-09-15');
        foreach ([['status' => 'CONFIRMED'], ['status' => 'REJECTED'], ['reviewed_at' => now()], ['confirmed_at' => now()]] as $i => $state) {
            $row = $this->row($old, sprintf('2026-09-%02d', 20 + $i), $state + ['work_content' => 'Protected']);
            $snapshots[] = [$row, $row->getAttributes()];
        }
        $this->assertSame(['PROTECTED_RELATIONSHIP' => 4], $this->repair()['diagnostics']['reasons']);
        foreach ($snapshots as [$row, $before]) {
            $this->assertSame($before, $row->fresh()->getAttributes());
        }
    }

    public function test_touching_boundary_does_not_overlap_and_empty_source_narrows_once(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-15 12:00:00');
        $b = $this->assignment('2026-09-15 12:00:00');
        $morning = $this->row($a, '2026-09-15', ['segment_start' => '07:00:00', 'segment_end' => '12:00:00', 'work_content' => 'Morning']);
        $afternoon = $this->row($b, '2026-09-15', ['segment_start' => '12:00:00', 'segment_end' => '17:00:00', 'work_content' => 'Afternoon']);
        $this->assertSame(2, $this->repair()['diagnostics']['already_correct']);
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($this->period)['can_export']);
        $this->assertSame('Morning', $morning->fresh()->work_content);
        $this->assertSame('Afternoon', $afternoon->fresh()->work_content);
    }

    public function test_single_bch_whole_month_with_content_is_a_read_only_noop(): void
    {
        $a = $this->assignment('2026-09-01');
        foreach (range(1, 30) as $day) {
            $row = $this->row($a, sprintf('2026-09-%02d', $day), ['work_content' => 'Existing content']);
            $snapshots[] = [$row, $row->getAttributes()];
        }
        $result = $this->repair();
        $this->assertSame(30, $result['diagnostics']['already_correct']);
        $this->assertSame(0, $result['repaired']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame(0, $result['unresolved']);
        foreach ($snapshots as [$row, $before]) {
            $this->assertSame($before, $row->fresh()->getAttributes());
        }
        $this->assertSame(0, ActivityLog::where('event', 'reconciliation.links_repaired')->count());
    }

    public function test_handover_day_content_relinks_without_changing_the_segment(): void
    {
        $a = $this->assignment('2026-09-15 07:30:00');
        $row = $this->row(null, '2026-09-15', ['segment_start' => '07:30:00', 'segment_end' => '11:30:00', 'work_content' => 'Handover']);
        $this->assertSame(1, $this->repair()['repaired']);
        $this->assertSame($a->id, (int) $row->fresh()->machine_assignment_id);
        $this->assertSame('07:30:00', $row->fresh()->segment_start);
        $this->assertSame('11:30:00', $row->fresh()->segment_end);
        $this->assertSame('Handover', $row->fresh()->work_content);
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($this->period)['can_export']);
    }

    public function test_three_assignment_dependency_chain_is_completed_in_one_atomic_repair(): void
    {
        $a = $this->assignment('2026-09-15', '2026-09-15 08:00:00');
        $b = $this->assignment('2026-09-15 08:00:00', '2026-09-15 16:00:00');
        $c = $this->assignment('2026-09-15 16:00:00');
        $first = $this->row(null, '2026-09-15', ['segment_start' => '00:00:00', 'segment_end' => '08:00:00', 'work_content' => 'First']);
        $second = $this->row($a, '2026-09-15', ['segment_start' => '08:00:00', 'segment_end' => '16:00:00', 'work_content' => 'Second']);
        $third = $this->row($b, '2026-09-15', ['segment_start' => '16:00:00', 'segment_end' => '23:59:59', 'work_content' => 'Third']);
        $this->assertSame(3, $this->repair()['repaired']);
        foreach ([[$first, $a], [$second, $b], [$third, $c]] as [$row, $target]) {
            $this->assertSame($target->id, (int) $row->fresh()->machine_assignment_id);
        }
        $this->assertSame(0, $this->repair()['repaired']);
    }

    public function test_identity_cycle_is_diagnostic_instead_of_a_partial_swap(): void
    {
        $a = $this->assignment('2026-09-15', '2026-09-15 12:00:00');
        $b = $this->assignment('2026-09-15 12:00:00');
        $first = $this->row($a, '2026-09-15', ['segment_start' => '12:00:00', 'segment_end' => '17:00:00', 'work_content' => 'First']);
        $second = $this->row($b, '2026-09-15', ['segment_start' => '07:00:00', 'segment_end' => '12:00:00', 'work_content' => 'Second']);
        $before = [$first->getAttributes(), $second->getAttributes()];
        $this->assertSame(['TARGET_DUPLICATE' => 2], $this->repair()['diagnostics']['reasons']);
        $this->assertSame($before, [$first->fresh()->getAttributes(), $second->fresh()->getAttributes()]);
    }

    public function test_reassignment_audit_failure_rolls_back_payload_and_identity(): void
    {
        $old = $this->assignment('2026-09-01', '2026-09-14 23:59:59');
        $this->assignment('2026-09-15');
        $row = $this->row($old, '2026-09-20', ['work_content' => 'HUMAN', 'regular_minutes' => 321]);
        $before = $row->getAttributes();
        DB::unprepared("CREATE TRIGGER reject_stabilization_audit BEFORE INSERT ON activity_logs WHEN NEW.event = 'reconciliation.links_repaired' BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        try {
            $this->repair();
            $this->fail('Expected audit failure');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(0, ActivityLog::where('event', 'reconciliation.links_repaired')->count());
    }

    private function assignment(string $start, ?string $end = null, array $changes = []): MachineAssignment
    {
        return MachineAssignment::create($changes + ['machine_id' => $this->machine->id, 'project_id' => $this->project->id,
            'command_center_id' => $this->bch->id, 'time_in' => $start, 'time_out' => $end]);
    }

    private function row(?MachineAssignment $source, string $date, array $changes = []): ReconciliationRow
    {
        return $this->period->rows()->create($changes + ['machine_id' => $this->machine->id,
            'machine_assignment_id' => $source?->id, 'project_id' => $source?->project_id,
            'command_center_id' => $source?->command_center_id, 'work_date' => $date,
            'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT'])->fresh();
    }

    private function repair(): array
    {
        return app(ReconciliationLinkRepairService::class)->repair($this->period, null);
    }
}
