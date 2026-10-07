<?php

namespace Tests\Feature\Reconciliation;

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
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\MachineAssignmentTimelineService;
use App\Services\Reconciliation\DailyPhotoSyncService;
use App\Services\Reconciliation\ReconciliationExportValidator;
use App\Services\Reconciliation\ReconciliationGenerator;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnassignedGapRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Machine $machine;

    private Project $project;

    private CommandCenter $a;

    private CommandCenter $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = Machine::create(['asset_code' => 'T-XX0694', 'chassis_no' => 'GAP', 'company' => 'SGC', 'status' => 'ACTIVE', 'created_at' => '2026-01-01']);
        $this->project = Project::create(['name' => 'Gap project']);
        $this->a = CommandCenter::create(['name' => 'A']);
        $this->b = CommandCenter::create(['name' => 'B']);
    }

    public function test_cross_day_gap_cleans_only_empty_drafts_and_never_fills_timeline(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-01 00:00:00');
        $b = $this->assignment('2026-09-10', null, $this->b);
        $period = $this->period('2026-09');
        foreach (range(1, 9) as $day) {
            $this->row($period, $a, sprintf('2026-09-%02d', $day));
        }
        $before = DB::table('machine_assignments')->orderBy('id')->get()->toJson();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(9, $result['removed']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(0, $period->rows()->count());
        $this->assertSame($before, DB::table('machine_assignments')->orderBy('id')->get()->toJson());
        $this->assertSame(9, ActivityLog::where('event', 'reconciliation.stale_row_removed')->count());
        $audit = json_decode(DB::table('activity_logs')->where('event', 'reconciliation.stale_row_removed')->first()->properties, true);
        $this->assertSame('LEGITIMATE_UNASSIGNED_GAP', $audit['timeline_context']);
        $this->assertArrayHasKey('daily_ocr_job_ids', $audit['row']);
        $again = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame([0, 0, 0], [$again['repaired'], $again['removed'], $again['unresolved']]);
        $this->assertSame(9, ActivityLog::where('event', 'reconciliation.stale_row_removed')->count());
        app(ReconciliationGenerator::class)->generate($period, true);
        $this->assertSame(0, $period->rows()->where('work_date', '<', '2026-09-10')->count());
        $this->assertSame(21, $period->rows()->where('machine_assignment_id', $b->id)->count());
    }

    public function test_cross_month_gap_is_independent_of_repair_order_and_active_is_allowed(): void
    {
        $a = $this->assignment('2026-01-01 08:45:00', '2026-08-08 14:24:00');
        $this->assignment('2026-10-13 14:24:00', null, $this->b);
        $sep = $this->period('2026-09');
        foreach (range(1, 30) as $day) {
            $this->row($sep, $a, sprintf('2026-09-%02d', $day));
        }
        $aug = $this->period('2026-08');
        $augRow = $this->row($aug, $a, '2026-08-08', ['segment_start' => '14:24:00']);
        $oct = $this->period('2026-10');
        $octRow = $this->row($oct, $a, '2026-10-13', ['segment_end' => '14:24:00']);
        $before = app(ReconciliationExportValidator::class)->validate($sep);
        $this->assertStringNotContainsString('đang hoạt động nhưng không có lịch', $before['blocking']->implode(' '));
        $this->assertStringContainsString('LEGITIMATE_UNASSIGNED_GAP', $before['blocking']->implode(' '));
        $this->assertSame(30, app(ReconciliationLinkRepairService::class)->repair($sep, null)['removed']);
        $this->assertNotNull($augRow->fresh());
        $this->assertNotNull($octRow->fresh());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($sep)['can_export']);
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($oct, null)['removed']);
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($aug, null)['removed']);
    }

    public function test_each_business_payload_and_real_canonical_pair_is_preserved_in_gap(): void
    {
        $a = $this->assignment('2026-01-01', '2026-08-08 14:24:00');
        $this->assignment('2026-10-13 14:24:00', null, $this->b);
        $period = $this->period('2026-09');
        $states = [['ocr_check_in_raw' => '11:18:00'], ['daily_ocr_job_ids' => [1, 2]], ['gps_check_in' => '11:00:00'],
            ['regular_minutes' => 90], ['manually_edited_at' => now()], ['notes' => 'HUMAN'],
            ['journal_row_ids' => [21]], ['ai_reconciliation_job_id' => 31], ['status' => 'REVIEWED']];
        foreach ($states as $i => $state) {
            $row = $this->row($period, $a, sprintf('2026-09-%02d', $i + 1), $state);
            $snapshots[] = [$row, $row->getAttributes()];
        }
        $case = $this->canonical($a, '2026-09-15');
        $interval = DB::table('daily_photo_intervals')->first();
        $row = $this->row($period, $a, '2026-09-15', ['daily_intervals' => [['canonical_interval_id' => $interval->id]]]);
        $snapshots[] = [$row, $row->getAttributes()];
        $tables = ['daily_photo_cases', 'daily_photo_case_evidence', 'daily_photo_intervals', 'ocr_jobs', 'zalo_attachments', 'zalo_messages'];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        for ($run = 0; $run < 2; $run++) {
            $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
            $this->assertSame(0, $result['removed']);
            $this->assertSame(0, $result['repaired']);
            $this->assertSame(['UNASSIGNED_GAP_REQUIRES_REVIEW' => 9, 'PROTECTED_RELATIONSHIP' => 1], $result['diagnostics']['reasons']);
            foreach ($snapshots as [$row, $payload]) {
                $this->assertSame($payload, $row->fresh()->getAttributes());
            }
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
            }
        }
        $this->assertSame(0, ActivityLog::where('event', 'like', 'reconciliation.%')->count());
    }

    public function test_same_day_one_minute_gap_and_contiguous_boundary_preserve_generator_and_validator(): void
    {
        foreach (['15:00:00', '15:01:00'] as $in) {
            DB::table('reconciliation_rows')->delete();
            DB::table('machine_assignments')->delete();
            $a = $this->assignment('2026-08-01', '2026-09-15 15:00:00');
            $b = $this->assignment('2026-09-15 '.$in, null, $this->b);
            $period = $this->period('2026-09');
            app(ReconciliationGenerator::class)->generate($period, true);
            $this->assertSame(2, $period->rows()->whereDate('work_date', '2026-09-15')->count());
            $this->assertSame('15:00:00', $period->rows()->where('machine_assignment_id', $a->id)->whereDate('work_date', '2026-09-15')->value('segment_end'));
            $this->assertSame($in, $period->rows()->where('machine_assignment_id', $b->id)->whereDate('work_date', '2026-09-15')->value('segment_start'));
            $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
            $this->assertSame([0, 0, 0], [$result['repaired'], $result['removed'], $result['unresolved']]);
            $validation = app(ReconciliationExportValidator::class)->validate($period);
            $this->assertTrue($validation['can_export']);
            $this->assertTrue($validation['warnings']->isEmpty());
            if ($in === '15:01:00') {
                $gap = $this->row($period, $a, '2026-09-16');
                $gap->update(['work_date' => '2026-09-15', 'machine_assignment_id' => null, 'segment_start' => '15:00:00', 'segment_end' => '15:01:00']);
                // Unlinked rows cannot be silently deleted: no source provenance.
                $this->assertSame('LEGITIMATE_UNASSIGNED_GAP', app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['rows'][0]['timeline_context']);
            }
        }
    }

    public function test_a_gap_b_gap_c_keeps_each_interval_independent(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-05');
        $b = $this->assignment('2026-09-10', '2026-09-15', $this->b);
        $c = $this->assignment('2026-09-20');
        $this->event($a, $b);
        $this->event($b, $c);
        $period = $this->period('2026-09');
        foreach (range(1, 30) as $day) {
            $this->row($period, $a, sprintf('2026-09-%02d', $day));
        }
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(10, $result['removed']);
        $this->assertSame(16, $result['repaired']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(5, $period->rows()->where('machine_assignment_id', $b->id)->count());
        $this->assertSame(11, $period->rows()->where('machine_assignment_id', $c->id)->count());
    }

    public function test_october_retroactive_edit_cleans_july_august_gap_without_open_period_dependency(): void
    {
        $a = $this->assignment('2026-01-01', '2026-09-01');
        $b = $this->assignment('2026-09-01', null, $this->b);
        $this->event($a, $b);
        $july = $this->period('2026-07');
        $aug = $this->period('2026-08');
        $this->row($july, $a, '2026-07-15');
        $this->row($aug, $a, '2026-08-15');
        $result = app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $b->id, '2026-07-01', '2026-09-10', null);
        $this->assertTrue($result['changed']);
        $this->assertSame(1, $result['propagation']['periods'][$july->id]['removed']);
        $this->assertSame(1, $result['propagation']['periods'][$aug->id]['removed']);
        $this->assertSame(0, ReconciliationRow::count());
        $this->assertFalse(app(MachineAssignmentTimelineService::class)->reviseTransfer($this->machine->id, $b->id, '2026-07-01', '2026-09-10', null)['changed']);
    }

    public function test_return_boundary_removes_empty_but_preserves_business_row(): void
    {
        $a = $this->assignment('2026-01-01', '2026-09-15 15:00:00');
        MachineEvent::create(['machine_id' => $this->machine->id, 'type' => 'RETURN', 'occurred_at' => '2026-09-15 15:00:00']);
        $this->assignment('2026-10-01', null, $this->b);
        $period = $this->period('2026-09');
        $this->row($period, $a, '2026-09-15', ['segment_start' => '15:00:00']);
        $rich = $this->row($period, $a, '2026-09-16', $this->payload());
        $before = $rich->getAttributes();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(['AFTER_RETURN_REQUIRES_REVIEW' => 1], $result['diagnostics']['reasons']);
        $this->assertSame('AFTER_RETURN', $result['diagnostics']['rows'][0]['timeline_context']);
        $this->assertSame($before, $rich->fresh()->getAttributes());
    }

    public function test_true_source_overlap_remains_manual(): void
    {
        $a = $this->assignment('2026-09-01', '2026-09-20');
        $this->assignment('2026-09-10', null, $this->b);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15');
        $before = $row->getAttributes();
        $this->assertSame(['TRUE_ASSIGNMENT_OVERLAP' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertStringContainsString('phân công nguồn thực sự chồng lấn', app(ReconciliationExportValidator::class)->validate($period)['warnings']->implode(' '));
    }

    public function test_t_xl0345_stale_materialized_segments_are_not_source_overlap(): void
    {
        $a = $this->assignment('2026-04-09 10:58:00', '2026-09-10 15:00:00');
        $b = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-09');
        $this->canonical($a, '2026-09-11', ['11:18:00', '13:24:00', '17:45:00', '18:01:00']);
        $row = $this->row($period, $a, '2026-09-11', ['evidence_status' => 'DAILY_PARTIAL', 'ocr_check_in_raw' => '11:18:00', 'ocr_check_out_raw' => '22:02:00']);
        $this->row($period, $b, '2026-09-11', ['segment_start' => '15:00:00', 'change_type' => 'TRANSFER_IN']);
        $nextCase = $this->canonical($b, '2026-09-12');
        $this->row($period, $b, '2026-09-12', ['daily_ocr_job_ids' => OcrJob::where('daily_photo_case_id', $nextCase->id)->pluck('id')->all()]);
        $tables = ['daily_photo_cases', 'daily_photo_case_evidence', 'daily_photo_intervals', 'ocr_jobs', 'zalo_attachments', 'zalo_messages'];
        foreach ($tables as $table) {
            $evidenceBefore[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $before = $row->getAttributes();
        $result = app(ReconciliationExportValidator::class)->validate($period);
        $this->assertStringNotContainsString('phân công nguồn thực sự chồng lấn', $result['warnings']->implode(' '));
        $this->assertStringContainsString('các dòng đối chiếu chồng lấn', $result['warnings']->implode(' '));
        $this->assertFalse($result['can_export']);
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['unresolved']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        foreach ($tables as $table) {
            $this->assertSame($evidenceBefore[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
    }

    public function test_gap_crossing_lifecycle_and_invalid_history_fail_closed(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-01');
        $this->assignment('2026-10-01', null, $this->b);
        MachineEvent::create(['machine_id' => $this->machine->id, 'type' => 'HANDOVER', 'occurred_at' => '2026-09-15 12:00:00']);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15');
        $before = $row->getAttributes();
        $this->assertSame(['LIFECYCLE_AMBIGUITY' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assignment('2026-06-10', '2026-06-09');
        $this->assertSame(['INVALID_TIMELINE' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_long_gap_1200_rows_has_bounded_queries_zero_hydration_and_idempotency(): void
    {
        $period = $this->period('2026-09');
        $seed = [];
        foreach (range(1, 40) as $n) {
            $machine = Machine::create(['asset_code' => 'GAP-'.$n, 'chassis_no' => 'GAP-'.$n, 'company' => 'SGC', 'status' => 'ACTIVE']);
            $base = ['machine_id' => $machine->id, 'project_id' => $this->project->id, 'command_center_id' => $this->a->id];
            $a = MachineAssignment::create($base + ['time_in' => '2026-01-01', 'time_out' => '2026-06-01']);
            MachineAssignment::create($base + ['time_in' => '2027-01-01']);
            foreach (range(1, 30) as $day) {
                $seed[] = $base + ['reconciliation_period_id' => $period->id, 'machine_assignment_id' => $a->id,
                    'work_date' => sprintf('2026-09-%02d', $day), 'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT'];
            }
        }
        foreach (array_chunk($seed, 100) as $chunk) {
            DB::table('reconciliation_rows')->insert($chunk);
        }
        $hydrated = 0;
        ReconciliationRow::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = hrtime(true);
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        fwrite(STDERR, sprintf("\nLong gap benchmark: 1200 rows; %.2f ms; %d queries; %d row models\n", (hrtime(true) - $start) / 1e6, $queries, $hydrated));
        $this->assertSame(1200, $result['removed']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertLessThan(40, $queries);
        $this->assertSame(0, $hydrated);
        $this->assertSame(0, $period->rows()->count());
        $logs = ActivityLog::count();
        $again = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame([0, 0, 0], [$again['repaired'], $again['removed'], $again['unresolved']]);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_same_day_stale_segment_inside_gap_is_removed_without_narrowing_into_source(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-15 15:00:00');
        $b = $this->assignment('2026-09-15 15:01:00', null, $this->b);
        $period = $this->period('2026-09');
        $this->row($period, $a, '2026-09-15', ['segment_start' => '15:00:00', 'segment_end' => '15:01:00']);
        $target = $this->row($period, $b, '2026-09-15', ['segment_start' => '15:01:00']);
        $before = $target->getAttributes();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame([0, 1, 0], [$result['repaired'], $result['removed'], $result['unresolved']]);
        $this->assertSame($before, $target->fresh()->getAttributes());
    }

    public function test_gap_cleanup_audit_failure_rolls_back_and_locked_period_is_protected(): void
    {
        $a = $this->assignment('2026-08-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15');
        $before = $row->getAttributes();
        DB::unprepared("CREATE TRIGGER reject_gap_audit BEFORE INSERT ON activity_logs WHEN NEW.event = 'reconciliation.stale_row_removed' BEGIN SELECT RAISE(ABORT, 'gap audit failure'); END");
        try {
            app(ReconciliationLinkRepairService::class)->repair($period, null);
            $this->fail('Expected audit failure');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('gap audit failure', $error->getMessage());
        }
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(0, ActivityLog::where('event', 'like', 'reconciliation.%')->count());
        $period->update(['status' => 'CONFIRMED']);
        $this->expectException(\RuntimeException::class);
        app(ReconciliationLinkRepairService::class)->repair($period, null);
    }

    public function test_canonical_only_gap_propagation_and_daily_sync_do_not_force_assignment_or_create_row(): void
    {
        config(['daily_photos.enabled' => true]);
        $a = $this->assignment('2026-08-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $period = $this->period('2026-09');
        $case = $this->canonical($a, '2026-09-15');
        $tables = ['daily_photo_cases', 'daily_photo_case_evidence', 'daily_photo_intervals', 'ocr_jobs', 'zalo_attachments'];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $result = app(\App\Services\Reconciliation\AssignmentRelationshipPropagation::class)->propagate($this->machine->id, '2026-08-08', '2026-10-13', null);
        $this->assertSame('LEGITIMATE_UNASSIGNED_GAP', $result['canonical_review'][0]['reason']);
        app(DailyPhotoSyncService::class)->sync($period);
        $this->assertSame(0, $period->rows()->count());
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertSame($a->id, (int) $case->fresh()->machine_assignment_id);
    }

    private function payload(): array
    {
        return ['work_content' => 'HUMAN', 'work_location' => 'Location', 'notes' => 'Keep', 'manually_edited_at' => '2026-10-01 10:00:00',
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

    private function event(MachineAssignment $old, MachineAssignment $target): void
    {
        MachineEvent::create(['machine_id' => $this->machine->id, 'type' => 'TRANSFER', 'occurred_at' => $target->time_in,
            'from_project_id' => $old->project_id, 'from_command_center_id' => $old->command_center_id,
            'to_project_id' => $target->project_id, 'to_command_center_id' => $target->command_center_id]);
    }

    private function canonical(MachineAssignment $assignment, string $date, array $times = ['07:30:00', '11:00:00']): DailyPhotoCase
    {
        $case = DailyPhotoCase::create(['scope_key' => 'assignment:'.$assignment->id.'|date:'.$date, 'machine_id' => $this->machine->id,
            'machine_assignment_id' => $assignment->id, 'work_date' => $date, 'status' => 'READY', 'source_version' => 'v1']);
        $members = [];
        foreach ($times as $i => $time) {
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
        foreach (array_chunk($members, 2) as $i => $pair) {
            DailyPhotoInterval::create(['daily_photo_case_id' => $case->id, 'sequence' => $i + 1,
                'start_evidence_id' => $pair[0]->id, 'end_evidence_id' => $pair[1]->id,
                'raw_start_at' => $date.' '.$times[$i * 2], 'raw_end_at' => $date.' '.$times[$i * 2 + 1],
                'raw_start_time' => $times[$i * 2], 'raw_end_time' => $times[$i * 2 + 1], 'pairing_policy_version' => 'v1']);
        }

        return $case;
    }
}
