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
use App\Services\DailyPhotoCaseService;
use App\Services\Reconciliation\DayBasedAssignmentOwnership;
use App\Services\Reconciliation\ReconciliationExportValidator;
use App\Services\Reconciliation\ReconciliationGenerator;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DayBasedBchOwnershipTest extends TestCase
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

    public function test_transfer_owns_the_whole_day_and_generator_keeps_all_journal_hours_once(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $b = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $job = $this->canonical($a, '2026-09-10')->evidenceMemberships()->first()->ocrJob;
        $job->update(['document_type' => 'WEEKLY_JOURNAL', 'review_status' => 'APPROVED']);
        $document = \App\Models\JournalDocument::create(['machine_id' => $this->machine->id, 'ocr_job_id' => $job->id, 'confidence' => 0.99]);
        foreach ([['08:00:00', '11:00:00', 180], ['16:00:00', '17:00:00', 60]] as $i => [$start, $end, $minutes]) {
            \App\Models\JournalRow::create(['journal_document_id' => $document->id, 'row_number' => $i + 1, 'confidence' => 0.99, 'work_date' => '2026-09-11',
                'start_time' => $start, 'end_time' => $end, 'total_minutes' => $minutes, 'work_content' => 'Journal '.$i]);
        }
        $period = $this->period('2026-09');
        app(ReconciliationGenerator::class)->generate($period, true);
        $this->assertSame(1, $period->rows()->whereDate('work_date', '2026-09-11')->count());
        $row = $period->rows()->whereDate('work_date', '2026-09-11')->first();
        $this->assertSame($b->id, $row->machine_assignment_id);
        $this->assertSame(240, $row->regular_minutes);
        $this->assertStringContainsString('Journal 0', $row->work_content);
        $this->assertStringContainsString('Journal 1', $row->work_content);
        $this->assertSame('00:00:00', $row->segment_start);
        $this->assertSame('23:59:59', $row->segment_end);
        $this->assertSame($a->id, $period->rows()->whereDate('work_date', '2026-09-10')->value('machine_assignment_id'));
        app(ReconciliationGenerator::class)->generate($period, true);
        $this->assertSame(30, $period->rows()->count());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
    }

    public function test_photos_before_and_after_transfer_share_one_new_bch_case_without_duplicates(): void
    {
        config(['daily_photos.enabled' => true]);
        $a = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $b = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $case = $this->canonical($a, '2026-09-11', ['11:18:00', '13:24:00', '17:45:00', '18:01:00']);
        $members = DB::table('daily_photo_case_evidence')->get()->toJson();
        $intervals = DB::table('daily_photo_intervals')->get()->toJson();
        // Metadata can already name the new scope while canonical still points to the old assignment.
        $job = OcrJob::first();
        $metadata = $job->daily_metadata;
        $metadata['case_materialization']['scope_key'] = 'assignment:'.$b->id.'|date:2026-09-11';
        $job->update(['daily_metadata' => $metadata]);
        foreach (OcrJob::all() as $job) {
            $result = app(DailyPhotoCaseService::class)->materialize($job, false, collect([$a, $b]));
            $this->assertSame($case->id, $result->id);
            $this->assertSame($b->id, $result->machine_assignment_id);
        }
        $this->assertSame($members, DB::table('daily_photo_case_evidence')->get()->toJson());
        $this->assertSame($intervals, DB::table('daily_photo_intervals')->get()->toJson());
        $this->assertSame(1, DailyPhotoCase::count());
    }

    public function test_business_payload_and_pairing_survive_legacy_duplicate_repair_and_second_run(): void
    {
        $a = $this->assignment('2026-04-09 10:58:00', '2026-09-10 15:00:00');
        $b = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-09');
        $case = $this->canonical($a, '2026-09-11', ['11:18:00', '13:24:00', '17:45:00', '18:01:00']);
        $parts = DailyPhotoInterval::all()->map(fn ($i) => ['canonical_interval_id' => $i->id])->all();
        $row = $this->row($period, $a, '2026-09-11', ['regular_minutes' => 180, 'regular_morning_start' => '08:00:00', 'regular_morning_end' => '11:00:00', 'daily_intervals' => $parts, 'daily_ocr_job_ids' => OcrJob::pluck('id')->all()]);
        $target = $this->row($period, $b, '2026-09-11', ['segment_start' => '15:00:00']);
        $before = $row->getAttributes();
        $members = DB::table('daily_photo_case_evidence')->get()->toJson();
        $intervals = DB::table('daily_photo_intervals')->get()->toJson();
        $history = DB::table('machine_assignments')->get()->toJson();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(1, $period->rows()->count());
        $this->assertNull($target->fresh());
        $this->assertSame($b->id, $row->fresh()->machine_assignment_id);
        $after = $row->fresh()->getAttributes();
        foreach (['machine_assignment_id', 'project_id', 'command_center_id', 'segment_start', 'segment_end', 'updated_at'] as $field) {
            unset($before[$field], $after[$field]);
        }
        $this->assertSame($before, $after);
        $this->assertSame($case->id, $case->fresh()->id);
        $this->assertSame($members, DB::table('daily_photo_case_evidence')->get()->toJson());
        $this->assertSame($intervals, DB::table('daily_photo_intervals')->get()->toJson());
        $this->assertSame($history, DB::table('machine_assignments')->get()->toJson());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        $logs = ActivityLog::count();
        $second = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame([0, 0, 0], [$second['repaired'], $second['removed'], $second['unresolved']]);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_return_day_belongs_to_old_bch_but_next_gap_day_stays_null(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-03 16:45:00');
        MachineEvent::create(['machine_id' => $this->machine->id, 'type' => 'RETURN', 'occurred_at' => '2026-09-03 16:45:00']);
        $period = $this->period('2026-09');
        $return = $this->row($period, $a, '2026-09-03', ['ocr_check_out_raw' => '20:00:00']);
        $gap = $this->row($period, $a, '2026-09-04', ['notes' => 'unassigned evidence']);
        $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['unresolved']);
        $this->assertSame($a->id, $return->fresh()->machine_assignment_id);
        $this->assertNull($gap->fresh()->machine_assignment_id);
        $before = $gap->fresh()->getAttributes();
        app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame($before, $gap->fresh()->getAttributes());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
    }

    public function test_manual_reviewed_and_locked_cases_are_not_changed(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-09');
        $row = $this->row($period, $a, '2026-09-11', ['manually_edited_at' => now(), 'notes' => 'HUMAN']);
        $before = $row->getAttributes();
        $this->assertSame(['PROTECTED_RELATIONSHIP' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $row->update(['manually_edited_at' => null, 'status' => 'REVIEWED']);
        $before = $row->fresh()->getAttributes();
        $this->assertSame(1, app(ReconciliationLinkRepairService::class)->repair($period, null)['unresolved']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $period->update(['status' => 'CONFIRMED']);
        $this->expectException(\RuntimeException::class);
        app(ReconciliationLinkRepairService::class)->repair($period, null);
    }

    public function test_multiple_valid_transfers_choose_final_bch_but_true_overlap_is_never_tiebroken(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-11 10:00:00');
        $b = $this->assignment('2026-09-11 10:00:00', '2026-09-11 15:00:00', $this->b);
        $c = $this->assignment('2026-09-11 15:00:00');
        $resolver = new DayBasedAssignmentOwnership([$a, $b, $c]);
        $this->assertSame($c->id, $resolver->resolve($this->machine->id, '2026-09-11')['assignment']->id);
        $b->time_out = '2026-09-11 15:01:00';
        $resolver = new DayBasedAssignmentOwnership([$a, $b, $c]);
        $this->assertSame('TRUE_ASSIGNMENT_OVERLAP', $resolver->resolve($this->machine->id, '2026-09-11')['reason']);
        $this->assertNull($resolver->resolve($this->machine->id, '2026-09-11')['assignment']);
    }

    public function test_production_boundary_snapshot_resolves_five_and_preserves_all_126_true_unassigned_days(): void
    {
        $this->machine->update(['status' => 'INACTIVE']);
        $facts = json_decode(file_get_contents(base_path('tests/Fixtures/reconciliation/phase17-3-day-boundaries.json')), true, 512, JSON_THROW_ON_ERROR);
        $resolver = new DayBasedAssignmentOwnership(array_map(fn ($a) => (object) $a, $facts['assignments']), array_map(fn ($e) => (object) $e, $facts['events']));
        $this->assertCount(126, $facts['normalized']);
        $contexts = [];
        foreach ($facts['normalized'] as $row) {
            $day = $resolver->resolve($row['machine_id'], $row['work_date']);
            $this->assertNull($day['assignment'], 'Normalized row #'.$row['id']);
            $this->assertNull($day['reason']);
            $contexts[] = $day['context']['timeline_context'];
        }
        $this->assertSame(9, count(array_filter($contexts, fn ($s) => $s === 'LEGITIMATE_UNASSIGNED_GAP')));
        $this->assertSame(117, count(array_filter($contexts, fn ($s) => $s === 'AFTER_RETURN')));
        $expected = [85904 => [256, 17], 85905 => [420, 28], 86442 => [422, 35], 87577 => [423, 35], 89197 => [296, 35]];
        foreach ($facts['focus'] as $row) {
            $day = $resolver->resolve($row['machine_id'], $row['work_date']);
            $this->assertNull($day['reason']);
            $this->assertSame($expected[$row['id']], [$day['assignment']->id, $day['assignment']->source_bch_id]);
            // Exercise the actual Validator against each resolved production boundary.
            $machine = Machine::create(['asset_code' => 'REPLAY-'.$row['id'], 'chassis_no' => 'REPLAY-'.$row['id'], 'company' => 'SGC', 'status' => 'INACTIVE']);
            $assignment = MachineAssignment::create(['machine_id' => $machine->id, 'project_id' => $this->project->id, 'command_center_id' => $this->b->id,
                'time_in' => $day['assignment']->physical_time_in, 'time_out' => $day['assignment']->physical_time_out]);
            $period = $this->period('2026-09');
            $this->row($period, $assignment, $row['work_date']);
        }
        $period = $this->period('2026-09');
        $before = $period->rows()->orderBy('id')->get()->toJson();
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['unresolved']);
        $this->assertSame($before, $period->rows()->orderBy('id')->get()->toJson());
    }

    public function test_repair_preserves_the_126_normalized_relationships_from_production_snapshot_twice(): void
    {
        $this->machine->update(['status' => 'INACTIVE']);
        $facts = json_decode(file_get_contents(base_path('tests/Fixtures/reconciliation/phase17-3-day-boundaries.json')), true, 512, JSON_THROW_ON_ERROR);
        $machines = [];
        $projects = [];
        $bchs = [];
        foreach ($facts['assignments'] as $a) {
            $machines[$a['machine_id']] ??= Machine::create(['asset_code' => 'NULL-'.$a['machine_id'], 'chassis_no' => 'NULL-'.$a['machine_id'], 'company' => 'SGC', 'status' => 'INACTIVE']);
            $projects[$a['project_id']] ??= Project::create(['name' => 'Snapshot project '.$a['project_id']]);
            $bchId = $a['source_bch_id'];
            $bchs[$bchId] ??= CommandCenter::create(['name' => 'Snapshot BCH '.$bchId]);
            MachineAssignment::create(['id' => $a['id'], 'machine_id' => $machines[$a['machine_id']]->id,
                'project_id' => $projects[$a['project_id']]->id, 'command_center_id' => $bchs[$bchId]->id,
                'time_in' => $a['time_in'], 'time_out' => $a['time_out']]);
        }
        foreach ($facts['events'] as $event) {
            MachineEvent::create(['machine_id' => $machines[$event['machine_id']]->id, 'type' => $event['type'], 'occurred_at' => $event['occurred_at']]);
        }
        $period = $this->period('2026-09');
        foreach ($facts['normalized'] as $row) {
            $period->rows()->create(['id' => $row['id'], 'machine_id' => $machines[$row['machine_id']]->id,
                'work_date' => $row['work_date'], 'segment_start' => $row['segment_start'], 'segment_end' => $row['segment_end'],
                'status' => 'DRAFT', 'notes' => 'Evidence retained '.$row['id']]);
        }
        $before = DB::table('reconciliation_rows')->orderBy('id')->get()->toJson();
        $history = DB::table('machine_assignments')->orderBy('id')->get()->toJson();
        $logs = ActivityLog::count();
        for ($run = 0; $run < 2; $run++) {
            $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
            $this->assertSame([0, 0, 0, 0], [$result['repaired'], $result['removed'], $result['normalized_unassigned'], $result['unresolved']]);
            $this->assertSame(126, $result['diagnostics']['already_correct']);
            $this->assertSame($before, DB::table('reconciliation_rows')->orderBy('id')->get()->toJson());
            $this->assertSame($history, DB::table('machine_assignments')->orderBy('id')->get()->toJson());
            $this->assertSame($logs, ActivityLog::count());
            $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
        }
    }

    public function test_invalid_timestamp_or_missing_final_bch_is_not_arbitrarily_resolved(): void
    {
        $base = ['id' => 1, 'machine_id' => 1, 'project_id' => 1, 'command_center_id' => 1, 'time_out' => null];
        foreach (['2026-09-11 25:00:00', '2026-09-31 15:00:00', 'bad date', null] as $stamp) {
            $resolver = new DayBasedAssignmentOwnership([(object) ($base + ['time_in' => $stamp])]);
            $this->assertSame('INVALID_TIMELINE', $resolver->resolve(1, '2026-09-11')['reason']);
            $this->assertNull($resolver->resolve(1, '2026-09-11')['assignment']);
        }
        $a = (object) ($base + ['time_in' => '2026-08-01 00:00:00']);
        $a->time_out = '2026-09-11 15:00:00';
        $b = (object) (['id' => 2, 'command_center_id' => null] + $base + ['time_in' => '2026-09-11 15:00:00']);
        $this->assertSame('NO_BCH_RESOLUTION', (new DayBasedAssignmentOwnership([$a, $b]))->resolve(1, '2026-09-11')['reason']);
        $b->command_center_id = 1;
        $b->time_in = '2026-08-01 00:00:00';
        $this->assertSame('TRUE_ASSIGNMENT_OVERLAP', (new DayBasedAssignmentOwnership([$a, $b]))->resolve(1, '2026-09-11')['reason']);
    }

    public function test_human_or_reviewed_ocr_canonical_is_protected_from_ownership_relink(): void
    {
        $a = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $case = $this->canonical($a, '2026-09-11');
        $period = $this->period('2026-09');
        $this->row($period, $a, '2026-09-11');
        foreach ([['machine_resolution_method' => 'HUMAN'], ['review_status' => 'APPROVED']] as $state) {
            $job = OcrJob::first();
            $job->update($state);
            $before = DB::table('daily_photo_cases')->get()->toJson();
            $this->assertSame(['PROTECTED_CANONICAL_RELATIONSHIP' => 1], app(ReconciliationLinkRepairService::class)->repair($period, null)['diagnostics']['reasons']);
            $this->assertSame($before, DB::table('daily_photo_cases')->get()->toJson());
            $this->assertSame($a->id, $case->fresh()->machine_assignment_id);
            $job->update(['machine_resolution_method' => null, 'review_status' => 'PENDING']);
        }
    }

    public function test_resync_scopes_unmaterialized_morning_and_evening_photos_to_new_bch_only(): void
    {
        config(['daily_photos.enabled' => true]);
        $a = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $b = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $case = $this->canonical($a, '2026-09-11', ['11:18:00', '13:24:00', '17:45:00', '18:01:00']);
        $jobs = OcrJob::pluck('id')->all();
        $case->delete(); // FK cleanup leaves the four original OCR jobs unmaterialized.
        $period = $this->period('2026-09');
        app(\App\Services\Reconciliation\DailyPhotoResyncService::class)->sync($period, $this->a->id);
        $this->assertSame(0, DailyPhotoCase::count());
        $this->assertSame(0, $period->rows()->count());
        app(\App\Services\Reconciliation\DailyPhotoResyncService::class)->sync($period, $this->b->id);
        $this->assertSame(1, DailyPhotoCase::count());
        $this->assertSame($b->id, DailyPhotoCase::first()->machine_assignment_id);
        $this->assertSame(1, $period->rows()->count());
        $this->assertSame($b->id, $period->rows()->first()->machine_assignment_id);
        $this->assertSame($jobs, OcrJob::pluck('id')->all());
        $this->assertSame(4, DailyPhotoCaseEvidence::count());
        $this->assertTrue(app(ReconciliationExportValidator::class)->validate($period)['can_export']);
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
