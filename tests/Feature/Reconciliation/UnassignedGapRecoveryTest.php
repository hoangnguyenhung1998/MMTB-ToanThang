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
            $this->assertSame(['PROTECTED_RELATIONSHIP' => 1], $result['diagnostics']['reasons']);
            $this->assertSame($run === 0 ? 9 : 0, $result['normalized_unassigned']);
            foreach ($snapshots as [$row, $payload]) {
                if ($row->status === 'REVIEWED') {
                    $this->assertSame($payload, $row->fresh()->getAttributes());
                } else {
                    $this->assertNormalized($row, $payload);
                }
            }
            foreach ($tables as $table) {
                if (! in_array($table, ['daily_photo_cases', 'ocr_jobs'], true)) {
                    $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
                }
            }
        }
        $this->assertNull($case->fresh()->machine_assignment_id);
        $this->assertSame('machine:'.$this->machine->id.'|date:2026-09-15|assignment:unresolved', $case->fresh()->scope_key);
        $this->assertCanonicalPayloadPreserved($before);
        $this->assertSame(9, ActivityLog::where('event', 'reconciliation.relationship_unassigned')->count());
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
                $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
                $this->assertNull($gap->fresh()->command_center_id);
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
        $this->assertSame([], $result['diagnostics']['reasons']);
        $this->assertSame(['AFTER_RETURN' => 1], $result['diagnostics']['unassigned_by_context']);
        $this->assertNormalized($rich, $before);
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
        $this->assertSame(['LIFECYCLE_AMBIGUITY' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $invalid = $this->assignment('2026-09-16', '2026-09-14');
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
        $this->assertSame([], $result['canonical_review']);
        $this->assertSame(1, $result['canonical_unassigned']);
        app(DailyPhotoSyncService::class)->sync($period);
        $this->assertSame(0, $period->rows()->count());
        foreach ($tables as $table) {
            if (! in_array($table, ['daily_photo_cases', 'ocr_jobs'], true)) {
                $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
            }
        }
        $this->assertNull($case->fresh()->machine_assignment_id);
        $this->assertCanonicalPayloadPreserved($before);
    }

    public function test_normalized_row_validates_and_daily_sync_preserves_every_payload_field(): void
    {
        config(['daily_photos.enabled' => true]);
        $a = $this->assignment('2026-01-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $period = $this->period('2026-09');
        $case = $this->canonical($a, '2026-09-15');
        $row = $this->row($period, $a, '2026-09-15', $this->payload());
        $before = $row->getAttributes();
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
        $this->assertNormalized($row, $before);
        $normalized = $row->fresh()->getAttributes();
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        app(DailyPhotoSyncService::class)->sync($period);
        $this->assertSame($normalized, $row->fresh()->getAttributes());
        $preview = app(DailyPhotoSyncService::class)->preview($row->fresh());
        $this->assertSame($case->id, $preview['case']->id);
        $this->assertCount(2, $preview['sources']);
        app(\App\Services\Reconciliation\DailyTimeAllocator::class)->assertWithinAssignment(
            ['regular_morning_start' => '07:30', 'regular_morning_end' => '11:00', 'regular_minutes' => 210], $row->fresh());
        $weekly = ReconciliationPeriod::create(['name' => 'Valid locked NULL scope', 'type' => 'WEEKLY', 'date_from' => '2026-09-14', 'date_to' => '2026-09-20', 'status' => 'CONFIRMED']);
        $weekly->rows()->create(['machine_id' => $this->machine->id, 'work_date' => '2026-09-15',
            'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'CONFIRMED']);
        $logs = ActivityLog::count();
        $again = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(0, $again['unresolved']);
        $this->assertSame(1, $again['diagnostics']['already_correct']);
        $this->assertSame($logs, ActivityLog::count());
        $this->assertSame($normalized, $row->fresh()->getAttributes());

    }

    public function test_unassigned_normalization_audit_failure_rolls_back_row_case_and_ocr(): void
    {
        $a = $this->assignment('2026-01-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $period = $this->period('2026-09');
        $this->canonical($a, '2026-09-15');
        $row = $this->row($period, $a, '2026-09-15', $this->payload());
        foreach (['reconciliation_rows', 'daily_photo_cases', 'ocr_jobs', 'daily_photo_case_evidence', 'daily_photo_intervals'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        DB::unprepared("CREATE TRIGGER reject_unassigned BEFORE INSERT ON activity_logs WHEN NEW.event = 'reconciliation.relationship_unassigned' BEGIN SELECT RAISE(ABORT, 'unassigned audit failure'); END");
        try {
            app(ReconciliationLinkRepairService::class)->repair($period, null);
            $this->fail('Expected audit rejection');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('unassigned audit failure', $error->getMessage());
        }
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->get()->toJson());
        }
    }

    public function test_populated_unassigned_case_conflict_and_locked_shared_case_preserve_all_data(): void
    {
        $a = $this->assignment('2026-01-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $period = $this->period('2026-09');
        $this->canonical($a, '2026-09-15');
        $row = $this->row($period, $a, '2026-09-15', $this->payload());
        DailyPhotoCase::create(['machine_id' => $this->machine->id, 'work_date' => '2026-09-15',
            'scope_key' => 'machine:'.$this->machine->id.'|date:2026-09-15|assignment:unresolved', 'status' => 'READY', 'source_version' => 'v1']);
        $before = $row->getAttributes();
        $this->assertSame(['CANONICAL_CONFLICT' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        DailyPhotoCase::whereNull('machine_assignment_id')->delete();
        $weekly = ReconciliationPeriod::create(['name' => 'Locked weekly', 'type' => 'WEEKLY', 'date_from' => '2026-09-14', 'date_to' => '2026-09-20', 'status' => 'CONFIRMED']);
        $this->row($weekly, $a, '2026-09-15', ['status' => 'CONFIRMED']);
        $weekly->update(['status' => 'CONFIRMED']);
        $this->assertSame(['PROTECTED_CANONICAL_RELATIONSHIP' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_invalid_timeline_is_scoped_and_reports_assignment_ids_and_times(): void
    {
        $a = $this->assignment('2026-01-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $this->assignment('2026-06-10', '2026-06-09');
        $this->assignment('2026-07-01', '2026-07-01');
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15', $this->payload());
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
        $invalid = $this->assignment('2026-09-16', '2026-09-14');
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $issue = $result['diagnostics']['rows'][0]['assignment_issues'][0];
        $this->assertSame($invalid->id, $issue['assignment_id']);
        $this->assertSame('REVERSED_INTERVAL', $issue['issue']);
        $this->assertSame('2026-09-16 00:00:00', $issue['time_in']);
        $this->assertFalse(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
    }

    public function test_before_handover_and_after_last_assignment_are_unassigned_and_missing_history_is_not(): void
    {
        $a = $this->assignment('2026-09-10', '2026-09-20');
        $period = $this->period('2026-09');
        foreach (['2026-09-05', '2026-09-25'] as $date) {
            $this->row($period, $a, $date, ['notes' => 'preserve']);
        }
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(2, $result['normalized_unassigned']);
        $this->assertSame(['BEFORE_FIRST_HANDOVER' => 1, 'AFTER_LAST_ASSIGNMENT' => 1], $result['diagnostics']['unassigned_by_context']);
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        MachineAssignment::where('machine_id', $this->machine->id)->delete();
        $this->assertFalse(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
    }

    public function test_unassigned_target_identity_conflict_is_manual_and_idempotent(): void
    {
        $a = $this->assignment('2026-01-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15', ['notes' => 'source']);
        $period->rows()->create(['machine_id' => $this->machine->id, 'work_date' => '2026-09-15',
            'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT', 'notes' => 'distinct target']);
        $before = DB::table('reconciliation_rows')->get()->toJson();
        for ($i = 0; $i < 2; $i++) {
            $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
            $this->assertSame(['UNASSIGNED_IDENTITY_CONFLICT' => 1], $result['diagnostics']['reasons']);
            $this->assertSame(0, $result['normalized_unassigned']);
            $this->assertSame($before, DB::table('reconciliation_rows')->get()->toJson());
        }
        $this->assertFalse(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        $row->update(['machine_assignment_id' => null, 'project_id' => null, 'command_center_id' => null]);
        $before = DB::table('reconciliation_rows')->get()->toJson();
        $this->assertSame(['UNASSIGNED_IDENTITY_CONFLICT' => 2], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, DB::table('reconciliation_rows')->get()->toJson());
    }

    public function test_null_gap_identity_does_not_block_disjoint_assignment_append_or_disappear_from_exports(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-15 15:00:00');
        $b = $this->assignment('2026-09-15 15:01:00', null, $this->b);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15', ['notes' => 'one minute gap', 'segment_start' => '15:00:00', 'segment_end' => '15:01:00']);
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
        app(ReconciliationGenerator::class)->generate($period, true);
        $this->assertSame(3, $period->rows()->whereDate('work_date', '2026-09-15')->count());
        $this->assertSame(1, $period->rows()->whereDate('work_date', '2026-09-15')->where('machine_assignment_id', $b->id)->count());
        app(ReconciliationGenerator::class)->generate($period, true);
        $this->assertSame(3, $period->rows()->whereDate('work_date', '2026-09-15')->count());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        $sheets = (new \App\Exports\ReconciliationBchWorkbookExport($period))->sheets();
        $this->assertCount(3, $sheets);
        $this->assertContains('Không BCH', array_map(fn ($sheet) => $sheet->title(), $sheets));
        $this->expectException(\RuntimeException::class);
        app(ReconciliationGenerator::class)->generate($period, false);
    }

    public function test_repair_flash_period_and_row_views_render_unassigned_and_diagnostics(): void
    {
        $a = $this->assignment('2026-01-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15', ['notes' => 'view preservation']);
        $this->actingAs(\App\Models\User::factory()->create());
        $this->post(route('reconciliation-periods.repair-links', $period))->assertSessionHas('success', fn ($message) => str_contains($message, 'chuẩn hóa 1 dòng Không BCH'));
        $this->get(route('reconciliation-periods.show', $period))->assertOk()->assertSee('Không BCH')->assertSee('LEGITIMATE_UNASSIGNED_GAP');
        $this->get(route('reconciliation-rows.show', [$period, $row]))->assertOk()->assertSee('Không BCH')->assertSee('view preservation');
    }

    public function test_return_event_conflicting_with_live_assignment_remains_manual(): void
    {
        $a = $this->assignment('2026-01-01');
        MachineEvent::create(['machine_id' => $this->machine->id, 'type' => 'RETURN', 'occurred_at' => '2026-09-10']);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15', ['notes' => 'conflicting timeline']);
        $before = $row->getAttributes();
        $this->assertSame(['LIFECYCLE_ASSIGNMENT_CONFLICT' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertStringContainsString('LIFECYCLE_ASSIGNMENT_CONFLICT', app(ReconciliationExportValidator::class)->validate($period)['blocking']->implode(' '));
    }

    public function test_unassigned_hours_crossing_assignment_boundary_remain_preserved_but_block_validation(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-15 15:00:00');
        $this->assignment('2026-09-15 15:01:00', null, $this->b);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15', ['segment_start' => '15:00:00', 'segment_end' => '15:01:00',
            'overtime_afternoon_start' => '15:00:00', 'overtime_afternoon_end' => '17:00:00', 'ot_afternoon_minutes' => 120]);
        $before = $row->getAttributes();
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
        $this->assertNormalized($row, $before);
        $this->assertStringContainsString('UNASSIGNED_TIME_CONFLICT', app(ReconciliationExportValidator::class)->validate($period)['blocking']->implode(' '));
    }

    public function test_empty_canonical_case_is_unassigned_with_stable_identity_and_populated_conflicts_are_preserved(): void
    {
        $a = $this->assignment('2026-01-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $period = $this->period('2026-09');
        $case = DailyPhotoCase::create(['machine_id' => $this->machine->id, 'machine_assignment_id' => $a->id,
            'work_date' => '2026-09-15', 'scope_key' => 'assignment:'.$a->id.'|date:2026-09-15', 'status' => 'COLLECTING', 'source_version' => 'v1']);
        $row = $this->row($period, $a, '2026-09-15', ['notes' => 'rich manual row']);
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
        $this->assertNull($case->fresh()->machine_assignment_id);
        $this->assertSame(1, DailyPhotoCase::count());
        $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
        DailyPhotoCase::create(['machine_id' => $this->machine->id, 'machine_assignment_id' => $a->id,
            'work_date' => '2026-09-16', 'scope_key' => 'assignment:'.$a->id.'|date:2026-09-16', 'status' => 'COLLECTING', 'source_version' => 'v1']);
        DailyPhotoCase::create(['machine_id' => $this->machine->id, 'work_date' => '2026-09-16',
            'scope_key' => 'machine:'.$this->machine->id.'|date:2026-09-16|assignment:unresolved', 'status' => 'READY', 'source_version' => 'v1']);
        $this->row($period, $a, '2026-09-16', ['notes' => 'distinct conflict']);
        $before = DB::table('daily_photo_cases')->orderBy('id')->get()->toJson();
        $this->assertSame(['CANONICAL_CONFLICT' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, DB::table('daily_photo_cases')->orderBy('id')->get()->toJson());

    }

    public function test_canonical_wholly_before_or_after_boundary_narrows_only_relationship_segment(): void
    {
        foreach ([['07:30:00', '11:00:00'], ['17:30:00', '18:00:00']] as $i => $times) {
            $day = 15 + $i;
            $date = '2026-09-'.$day;
            $a = $this->assignment('2026-08-01', $date.' 15:00:00');
            $b = $this->assignment($date.' 15:01:00', null, $this->b);
            $period = $this->period('2026-09');
            $case = $this->canonical($a, $date, $times);
            $row = $this->row($period, $a, $date, ['notes' => 'HUMAN preserved', 'manually_edited_at' => now(),
                'ocr_check_in_raw' => $times[0], 'ocr_check_out_raw' => $times[1]]);
            $before = $row->getAttributes();
            $photos = DB::table('daily_photo_case_evidence')->get()->toJson();
            $intervals = DB::table('daily_photo_intervals')->get()->toJson();
            $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
            $this->assertSame(1, $result['repaired']);
            $this->assertSame(0, $result['unresolved']);
            $after = $row->fresh()->getAttributes();
            foreach (['machine_assignment_id', 'project_id', 'command_center_id', 'segment_start', 'segment_end', 'updated_at'] as $field) {
                unset($before[$field], $after[$field]);
            }
            $this->assertSame($before, $after);
            $this->assertSame($i === 0 ? $a->id : $b->id, $row->fresh()->machine_assignment_id);
            $this->assertSame($i === 0 ? '15:00:00' : '23:59:59', $row->fresh()->segment_end);
            $this->assertSame($photos, DB::table('daily_photo_case_evidence')->get()->toJson());
            $this->assertSame($intervals, DB::table('daily_photo_intervals')->get()->toJson());
            $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['repaired']);
            // Isolate the next independent timeline without changing evidence fixtures.
            DB::table('reconciliation_rows')->delete();
            DB::table('daily_photo_cases')->delete();
            DB::table('machine_assignments')->delete();
        }
    }

    public function test_canonical_inside_one_assignment_does_not_narrow_conflicting_hours(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-15 15:00:00');
        $this->assignment('2026-09-15 15:01:00', null, $this->b);
        $period = $this->period('2026-09');
        $this->canonical($a, '2026-09-15', ['17:30:00', '18:00:00']);
        $row = $this->row($period, $a, '2026-09-15', ['regular_minutes' => 210, 'regular_morning_start' => '07:30:00', 'regular_morning_end' => '11:00:00']);
        $before = $row->getAttributes();
        $this->assertSame(['SEGMENT_AMBIGUITY' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_return_inside_proven_full_period_gap_does_not_invent_a_bch_requirement(): void
    {
        $a = $this->assignment('2026-01-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        MachineEvent::create(['machine_id' => $this->machine->id, 'type' => 'RETURN', 'occurred_at' => '2026-09-15 12:00:00']);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-15', ['notes' => 'already unassigned on both sides of return']);
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
        $this->assertNull($row->fresh()->command_center_id);
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
    }

    public function test_preloaded_date_candidates_cannot_rematerialize_gap_photos_into_future_bch(): void
    {
        config(['daily_photos.enabled' => true]);
        foreach ([['2026-09-10 15:00:00', '2026-09-11 15:00:00', '2026-09-11', '11:18'],
            ['2026-09-15 15:00:00', '2026-09-15 15:01:00', '2026-09-15', '15:00:30']] as $i => [$out, $in, $date, $time]) {
            $a = $this->assignment('2026-08-01', $out);
            $b = $this->assignment($in, null, $this->b);
            $message = ZaloMessage::create(['group_id' => 'gap', 'message_id' => 'preloaded-'.$i, 'sender_id' => 'gap',
                'sent_at' => $date.' '.$time, 'received_at' => $date.' '.$time, 'status' => 'STORED']);
            $attachment = ZaloAttachment::create(['zalo_message_id' => $message->id, 'attachment_index' => 0,
                'storage_disk' => 'local', 'storage_path' => 'test/preloaded-'.$i.'.jpg', 'sha256' => hash('sha256', 'preloaded-'.$i),
                'mime_type' => 'image/jpeg', 'byte_size' => 10, 'status' => 'STORED']);
            $job = OcrJob::create(['zalo_attachment_id' => $attachment->id, 'document_type' => 'DAILY_TIMEMARK',
                'status' => 'COMPLETED', 'machine_id' => $this->machine->id, 'extracted_date' => $date, 'extracted_time' => $time]);
            $before = DB::table('machine_assignments')->get()->toJson();
            $case = app(\App\Services\DailyPhotoCaseService::class)->materialize($job, false, collect([$a, $b]));
            $this->assertNull($case->machine_assignment_id);
            $this->assertSame('machine:'.$this->machine->id.'|date:'.$date.'|assignment:unresolved', $case->scope_key);
            $this->assertSame('NOT_FOUND', $job->fresh()->daily_metadata['case_materialization']['assignment_resolution_status']);
            $this->assertSame($attachment->id, $job->fresh()->zalo_attachment_id);
            $this->assertSame($before, DB::table('machine_assignments')->get()->toJson());
            MachineAssignment::where('machine_id', $this->machine->id)->delete();
        }
    }

    public function test_unassignment_preserves_opaque_ocr_rescue_json_object_and_list_types(): void
    {
        $a = $this->assignment('2026-01-01', '2026-08-08');
        $this->assignment('2026-10-13', null, $this->b);
        $period = $this->period('2026-09');
        $case = $this->canonical($a, '2026-09-15');
        $this->row($period, $a, '2026-09-15', ['notes' => 'opaque metadata preservation']);
        $job = DB::table('ocr_jobs')->where('daily_photo_case_id', $case->id)->first();
        $metadata = json_decode($job->daily_metadata);
        $metadata->ai_rescue_payload = (object) ['result' => (object) [], 'reference' => 'unchanged', 'items' => []];
        DB::table('ocr_jobs')->where('id', $job->id)->update(['daily_metadata' => json_encode($metadata)]);
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['normalized_unassigned']);
        $after = json_decode(DB::table('ocr_jobs')->where('id', $job->id)->value('daily_metadata'));
        $this->assertEquals($metadata->ai_rescue_payload, $after->ai_rescue_payload);
        $this->assertInstanceOf(\stdClass::class, $after->ai_rescue_payload->result);
        $this->assertSame([], $after->ai_rescue_payload->items);
        $this->assertSame($metadata->ocr_content, $after->ocr_content);
    }

    private function assertCanonicalPayloadPreserved(array $before): void
    {
        foreach (['daily_photo_cases', 'ocr_jobs'] as $table) {
            $old = json_decode($before[$table], true);
            $new = json_decode(DB::table($table)->orderBy('id')->get()->toJson(), true);
            foreach ($old as $i => $record) {
                if ($table === 'daily_photo_cases') {
                    unset($old[$i]['machine_assignment_id'], $new[$i]['machine_assignment_id'], $old[$i]['scope_key'], $new[$i]['scope_key']);
                } else {
                    $a = json_decode($old[$i]['daily_metadata'], true);
                    $b = json_decode($new[$i]['daily_metadata'], true);
                    foreach (['machine_assignment_id', 'scope_key', 'candidate_machine_assignment_ids'] as $field) {
                        unset($a['case_materialization'][$field], $b['case_materialization'][$field]);
                        $old[$i]['daily_metadata'] = json_encode($a);
                        $new[$i]['daily_metadata'] = json_encode($b);
                    }
                }
                unset($old[$i]['updated_at'], $new[$i]['updated_at']);
            }
            $this->assertSame($old, $new);
        }
    }

    private function assertNormalized(ReconciliationRow $row, array $before): void
    {
        $after = $row->fresh()->getAttributes();
        foreach (['machine_assignment_id', 'project_id', 'command_center_id'] as $field) {
            $this->assertNull($after[$field]);
            unset($before[$field], $after[$field]);
        }
        unset($before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);
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
