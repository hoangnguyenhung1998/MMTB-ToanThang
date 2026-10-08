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
use App\Services\Reconciliation\ReconciliationExportValidator;
use App\Services\Reconciliation\ReconciliationGenerator;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrossPeriodConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Machine $machine;

    private Project $project;

    private CommandCenter $a;

    private CommandCenter $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = Machine::create(['asset_code' => 'DAY-OWNER', 'chassis_no' => 'DAY', 'company' => 'SGC', 'status' => 'ACTIVE', 'created_at' => '2026-01-01']);
        $this->project = Project::create(['name' => 'Day project']);
        $this->a = CommandCenter::create(['name' => 'A']);
        $this->b = CommandCenter::create(['name' => 'B']);
    }

    public function test_october_link_only_duplicates_ignore_technical_caches_and_keep_hours_once(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        for ($d = 1; $d <= 31; $d++) {
            $date = sprintf('2026-10-%02d', $d);
            $payload = ['regular_minutes' => 210, 'regular_morning_start' => '07:30:00', 'regular_morning_end' => '11:00:00'];
            $this->row($period, $old, $date, $payload + ['change_note' => 'old source', 'evidence_signature' => str_repeat('a', 64)]);
            $this->row($period, $owner, $date, $payload + ['change_note' => 'new source', 'evidence_signature' => str_repeat('b', 64)]);
        }
        $before = DB::table('machine_assignments')->get()->toJson();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(31, $result['removed']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(31, $period->rows()->count());
        $this->assertSame(6510, (int) $period->rows()->sum('regular_minutes'));
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        $logs = ActivityLog::count();
        $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['removed']);
        $this->assertSame($logs, ActivityLog::count());
        app(ReconciliationGenerator::class)->generate($period, true);
        $this->assertSame(31, $period->rows()->count());
        $next = $this->period('2026-11');
        app(ReconciliationGenerator::class)->generate($next, true);
        app(ReconciliationGenerator::class)->generate($next, true);
        $this->assertSame(30, $next->rows()->count());
        $this->assertSame($before, DB::table('machine_assignments')->get()->toJson());
    }

    public function test_correct_owner_row_repairs_legacy_canonical_ocr_and_preserves_all_pairing(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $case = $this->canonical($old, '2026-10-02');
        $row = $this->row($period, $owner, '2026-10-02', ['daily_ocr_job_ids' => OcrJob::pluck('id')->all(),
            'daily_intervals' => DailyPhotoInterval::all()->map(fn ($i) => ['canonical_interval_id' => $i->id])->all()]);
        $members = DB::table('daily_photo_case_evidence')->get()->toJson();
        $intervals = DB::table('daily_photo_intervals')->get()->toJson();
        $this->assertStringContainsString('CANONICAL_OCR_CONFLICT', app(ReconciliationExportValidator::class)->validate($period)['blocking']->implode(' '));
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(1, $result['repaired']);
        $this->assertSame($owner->id, $case->fresh()->machine_assignment_id);
        $this->assertSame($members, DB::table('daily_photo_case_evidence')->get()->toJson());
        $this->assertSame($intervals, DB::table('daily_photo_intervals')->get()->toJson());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['repaired']);
        $this->assertSame($owner->id, $row->fresh()->machine_assignment_id);
    }

    public function test_new_period_generator_and_sync_reuse_existing_old_canonical_without_new_evidence(): void
    {
        config(['daily_photos.enabled' => true]);
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $case = $this->canonical($old, '2026-10-03');
        $period = $this->period('2026-10');
        app(\App\Services\Reconciliation\DailyPhotoSyncService::class)->sync($period);
        $this->assertSame($owner->id, $case->fresh()->machine_assignment_id);
        $this->assertSame(1, $period->rows()->count());
        app(\App\Services\Reconciliation\DailyPhotoResyncService::class)->sync($period);
        app(ReconciliationGenerator::class)->generate($period, true);
        app(ReconciliationGenerator::class)->generate($period, true);
        $this->assertSame(31, $period->rows()->count());
        $this->assertSame(1, DailyPhotoCase::count());
        $this->assertSame(2, DailyPhotoCaseEvidence::count());
        $this->assertSame(1, DailyPhotoInterval::count());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
    }

    public function test_complementary_descriptors_merge_without_overwriting_or_adding_time(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $source = $this->row($period, $old, '2026-10-02', ['work_content' => 'Work', 'notes' => 'Source note']);
        $target = $this->row($period, $owner, '2026-10-02', ['work_location' => 'Site']);
        $before = $target->getAttributes();
        $before['work_date'] = substr($before['work_date'], 0, 10);
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(1, $result['removed']);
        $this->assertNull($source->fresh());
        $this->assertSame('Work', $target->fresh()->work_content);
        $this->assertSame('Site', $target->fresh()->work_location);
        $this->assertSame('Source note', $target->fresh()->notes);
        $audit = ActivityLog::where('event', 'reconciliation.stale_row_removed')->sole()->properties;
        $this->assertSame($before, $audit['target']);
        $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['removed']);
    }

    public function test_conflicting_hours_and_protected_rows_stay_unchanged_and_sync_cannot_clear_evidence(): void
    {
        config(['daily_photos.enabled' => true]);
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->row($period, $old, '2026-10-02', ['regular_minutes' => 120]);
        $this->row($period, $owner, '2026-10-02', ['regular_minutes' => 210]);
        $this->row($period, $old, '2026-10-03', ['manually_edited_at' => now(), 'notes' => 'HUMAN']);
        $this->row($period, $owner, '2026-10-03');
        $snapshot = $period->rows()->orderBy('id')->get()->toJson();
        $logs = ActivityLog::count();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(2, $result['unresolved']);
        app(\App\Services\Reconciliation\DailyPhotoSyncService::class)->sync($period);
        $this->assertSame($snapshot, $period->rows()->orderBy('id')->get()->toJson());
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_two_populated_canonical_scopes_and_human_ocr_are_not_consolidated(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->canonical($old, '2026-10-02');
        $this->canonical($owner, '2026-10-02');
        $case = $this->canonical($old, '2026-10-03');
        OcrJob::where('daily_photo_case_id', $case->id)->update(['machine_resolution_method' => 'HUMAN']);
        $this->row($period, $owner, '2026-10-02');
        $this->row($period, $owner, '2026-10-03');
        $snapshot = DB::table('daily_photo_cases')->get()->toJson();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(['CANONICAL_CONFLICT' => 1, 'PROTECTED_CANONICAL_RELATIONSHIP' => 1], $result['diagnostics']['reasons']);
        $this->assertSame($snapshot, DB::table('daily_photo_cases')->get()->toJson());
    }

    public function test_scoped_consistency_audit_is_select_only_and_redacts_sensitive_values(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->row($period, $old, '2026-10-02', ['notes' => 'SENSITIVE-TEXT']);
        $this->row($period, $owner, '2026-10-02', ['notes' => 'SENSITIVE-TEXT']);
        $this->row($period, $old, '2026-10-03');
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $report = app(\App\Services\Reconciliation\ReconciliationConsistencyAuditService::class)->audit($period, $this->machine->id, '2026-10-02', '2026-10-02');
        $this->assertSame(2, $report['summary']['rows']);
        $this->assertSame(1, $report['summary']['duplicate_pairs']['B']);
        $this->assertArrayHasKey('DAY_OWNERSHIP_MISMATCH', $report['validation']['blocking_by_reason']);
        $this->assertStringNotContainsString('SENSITIVE-TEXT', json_encode($report));
        foreach ($queries as $sql) {
            $this->assertStringStartsWith('select', strtolower(ltrim($sql)));
        }
        $this->artisan('reconciliation:consistency-audit', ['period' => $period->id, '--from' => '2026-10-32'])->assertFailed();
        $this->artisan('reconciliation:consistency-audit', ['period' => $period->id, '--from' => '2026-09-30'])->assertFailed();
    }

    public function test_preview_rolls_back_rows_canonical_ocr_and_audit_then_real_repair_is_idempotent(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $case = $this->canonical($old, '2026-10-02');
        $this->row($period, $old, '2026-10-02', ['notes' => 'payload']);
        $this->row($period, $owner, '2026-10-02');
        foreach (['reconciliation_rows', 'daily_photo_cases', 'ocr_jobs', 'daily_photo_case_evidence', 'daily_photo_intervals', 'activity_logs'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $service = app(ReconciliationLinkRepairService::class);
        $preview = $service->preview($period);
        $this->assertSame([2, 1], [$preview['before_rows'], $preview['after_rows']]);
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $result = $service->repair($period, null);
        $this->assertSame($preview['repair'], $result);
        $this->assertSame($owner->id, $case->fresh()->machine_assignment_id);
        $this->assertSame(0, $service->repair($period, null)['removed']);
    }

    public function test_wrong_day_ocr_reference_is_a_real_conflict_and_never_relinked(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->canonical($old, '2026-10-03');
        $row = $this->row($period, $owner, '2026-10-02', ['daily_ocr_job_ids' => OcrJob::pluck('id')->all()]);
        $before = $row->getAttributes();
        $this->assertSame(['CANONICAL_OCR_CONFLICT' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    public function test_default_generator_replay_preserves_existing_hours_and_row_ids(): void
    {
        $owner = $this->assignment('2026-08-01');
        $period = $this->period('2026-10');
        $row = $this->row($period, $owner, '2026-10-02', ['regular_minutes' => 210,
            'regular_morning_start' => '07:30:00', 'regular_morning_end' => '11:00:00']);
        $before = $row->getAttributes();
        app(ReconciliationGenerator::class)->generate($period);
        app(ReconciliationGenerator::class)->generate($period);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(31, $period->rows()->count());
    }

    public function test_empty_duplicate_and_identical_photo_payload_preserve_original_evidence_once(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->row($period, $old, '2026-10-01');
        $this->row($period, $owner, '2026-10-01', ['segment_start' => '15:00:00']);
        $case = $this->canonical($old, '2026-10-02');
        $jobs = OcrJob::pluck('id')->all();
        $this->row($period, $old, '2026-10-02', ['daily_ocr_job_ids' => $jobs, 'change_type' => 'HANDOVER']);
        $this->row($period, $owner, '2026-10-02', ['daily_ocr_job_ids' => array_reverse($jobs), 'change_type' => 'TRANSFER_IN']);
        $members = DB::table('daily_photo_case_evidence')->get()->toJson();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(2, $period->rows()->count());
        $this->assertSame($owner->id, $case->fresh()->machine_assignment_id);
        $this->assertSame($members, DB::table('daily_photo_case_evidence')->get()->toJson());
        $this->assertSame($jobs, OcrJob::pluck('id')->all());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
    }

    public function test_human_evidence_duplicate_and_shared_locked_canonical_require_review(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $case = $this->canonical($owner, '2026-10-02');
        $ids = OcrJob::pluck('id')->all();
        OcrJob::where('daily_photo_case_id', $case->id)->update(['machine_resolution_method' => 'HUMAN']);
        $this->row($period, $old, '2026-10-02', ['daily_ocr_job_ids' => $ids]);
        $this->row($period, $owner, '2026-10-02', ['daily_ocr_job_ids' => $ids]);
        $legacy = $this->canonical($old, '2026-10-03');
        $shared = ReconciliationPeriod::create(['name' => 'Locked overlap', 'type' => 'MONTHLY',
            'date_from' => '2026-10-03', 'date_to' => '2026-10-03', 'status' => 'CONFIRMED']);
        // Locked historical row may have NULL/stale links but still reference the old canonical.
        $protected = $this->row($shared, $old, '2026-10-03', ['status' => 'CONFIRMED',
            'daily_ocr_job_ids' => OcrJob::where('daily_photo_case_id', $legacy->id)->pluck('id')->all()]);
        $protected->update(['machine_assignment_id' => null]);
        $this->row($period, $owner, '2026-10-03');
        $snapshot = $period->rows()->orderBy('id')->get()->toJson();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertEquals(['PROTECTED_DUPLICATE' => 1, 'PROTECTED_CANONICAL_RELATIONSHIP' => 1], $result['diagnostics']['reasons']);
        $this->assertSame($snapshot, $period->rows()->orderBy('id')->get()->toJson());
        $this->assertSame($old->id, $legacy->fresh()->machine_assignment_id);
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
