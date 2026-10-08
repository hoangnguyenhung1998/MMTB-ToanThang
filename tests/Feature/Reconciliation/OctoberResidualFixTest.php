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
use App\Services\Reconciliation\ReconciliationGenerator;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OctoberResidualFixTest extends TestCase
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

    public function test_replay_repairs_stale_ocr_metadata_when_canonical_and_membership_are_already_correct(): void
    {
        config(['daily_photos.enabled' => true]);
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $case = $this->canonical($owner, '2026-10-02');
        $job = OcrJob::first();
        $metadata = $job->daily_metadata;
        $metadata['case_materialization']['operator_note'] = 'OPAQUE_LINK_NOTE';
        $metadata['case_materialization']['scope_key'] = 'assignment:'.$old->id.'|date:2026-10-02';
        $metadata['case_materialization']['machine_assignment_id'] = $old->id;
        $job->update(['daily_metadata' => $metadata]);
        $members = DB::table('daily_photo_case_evidence')->get()->toJson();
        $intervals = DB::table('daily_photo_intervals')->get()->toJson();
        app(\App\Services\DailyPhotoCaseService::class)->materialize($job, false);
        $this->assertSame($owner->id, $job->fresh()->daily_metadata['case_materialization']['machine_assignment_id']);
        $this->assertSame($case->scope_key, $job->fresh()->daily_metadata['case_materialization']['scope_key']);
        $this->assertSame('unchanged', $job->fresh()->daily_metadata['ocr_content']);
        $this->assertSame('OPAQUE_LINK_NOTE', $job->fresh()->daily_metadata['case_materialization']['operator_note']);
        $this->assertStringNotContainsString('OPAQUE_LINK_NOTE', ActivityLog::all()->toJson());
        $this->assertSame($members, DB::table('daily_photo_case_evidence')->get()->toJson());
        $this->assertSame($intervals, DB::table('daily_photo_intervals')->get()->toJson());
        $logs = ActivityLog::count();
        app(\App\Services\DailyPhotoCaseService::class)->materialize($job->fresh(), false);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_machine_day_occupancy_blocks_insertion_even_with_a_zero_length_legacy_segment(): void
    {
        config(['daily_photos.enabled' => true]);
        $old = $this->assignment('2026-08-01', '2026-09-11 15:00:00');
        $owner = $this->assignment('2026-09-11 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $legacy = $this->row($period, $old, '2026-10-02', ['segment_start' => '15:00:00', 'segment_end' => '15:00:00', 'regular_minutes' => 210]);
        $this->canonical($owner, '2026-10-02');
        $before = $legacy->getAttributes();
        app(\App\Services\Reconciliation\DailyPhotoSyncService::class)->sync($period);
        app(\App\Services\Reconciliation\DailyPhotoSyncService::class)->sync($period);
        $this->assertSame(1, $period->rows()->count());
        $this->assertSame($before, $legacy->fresh()->getAttributes());
    }

    public function test_metadata_repair_protects_human_manual_reviewed_and_shared_locked_evidence(): void
    {
        config(['daily_photos.enabled' => true]);
        $owner = $this->assignment('2026-09-30 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $cases = [];
        foreach (['HUMAN', 'MANUAL', 'REVIEWED', 'LOCKED'] as $index => $protection) {
            $date = '2026-10-0'.($index + 1);
            $case = $this->canonical($owner, $date);
            $job = OcrJob::where('daily_photo_case_id', $case->id)->first();
            $metadata = $job->daily_metadata;
            $metadata['case_materialization']['machine_assignment_id'] = 99999;
            $fields = ['daily_metadata' => $metadata];
            if ($protection === 'HUMAN') {
                $fields['machine_resolution_method'] = 'HUMAN';
            } elseif ($protection === 'MANUAL') {
                $fields['ocr_final_source'] = 'MANUAL';
            } elseif ($protection === 'REVIEWED') {
                $fields['reviewed_at'] = now();
            } else {
                $shared = ReconciliationPeriod::create(['name' => 'Locked overlap', 'type' => 'MONTHLY',
                    'date_from' => $date, 'date_to' => $date, 'status' => 'CONFIRMED']);
                $this->row($shared, $owner, $date, ['status' => 'CONFIRMED', 'daily_ocr_job_ids' => [$job->id]]);
            }
            $job->update($fields);
            $this->row($period, $owner, $date, ['daily_ocr_job_ids' => [$job->id]]);
            $cases[] = $job->fresh();
        }
        $before = DB::table('ocr_jobs')->get()->toJson();
        foreach ($cases as $job) {
            app(\App\Services\DailyPhotoCaseService::class)->materialize($job, false);
        }
        app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame($before, DB::table('ocr_jobs')->get()->toJson());
        $this->assertSame(0, ActivityLog::where('event', 'reconciliation.ocr_relationship_metadata_repaired')->count());
    }

    public function test_repair_corrects_metadata_without_changing_rows_pairing_or_previous_month_and_is_idempotent(): void
    {
        $owner = $this->assignment('2026-08-01', null, $this->b);
        $september = $this->period('2026-09');
        $this->row($september, $owner, '2026-09-02', ['regular_minutes' => 321]);
        $prior = $september->rows()->get()->toJson();
        $period = $this->period('2026-10');
        $this->canonical($owner, '2026-10-02');
        $job = OcrJob::first();
        $metadata = $job->daily_metadata;
        $metadata['case_materialization']['machine_assignment_id'] = 99999;
        $job->update(['daily_metadata' => $metadata]);
        $row = $this->row($period, $owner, '2026-10-02', ['daily_ocr_job_ids' => [$job->id], 'regular_minutes' => 210]);
        $before = $row->getAttributes();
        $service = app(ReconciliationLinkRepairService::class);
        $service->repair($period, null);
        $this->assertSame($owner->id, $job->fresh()->daily_metadata['case_materialization']['machine_assignment_id']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $snapshot = DB::table('ocr_jobs')->get()->toJson();
        $logs = ActivityLog::count();
        $service->repair($period, null);
        $this->assertSame($snapshot, DB::table('ocr_jobs')->get()->toJson());
        $this->assertSame($logs, ActivityLog::count());
        $this->assertSame($prior, $september->rows()->get()->toJson());
    }

    public function test_detailed_audit_separates_stored_reference_conflicts_from_daily_owner_and_keeps_conflicting_intervals(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-30 15:00:00');
        $owner = $this->assignment('2026-09-30 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $case = $this->canonical($owner, '2026-10-02');
        $jobs = OcrJob::pluck('id')->all();
        $interval = DailyPhotoInterval::first();
        $this->row($period, $old, '2026-10-02', ['daily_ocr_job_ids' => $jobs,
            'daily_intervals' => [['canonical_interval_id' => $interval->id, 'start' => '07:30:00', 'end' => '11:00:00', 'notes' => 'SECRET_NOTE']]]);
        $this->row($period, $owner, '2026-10-02', ['daily_ocr_job_ids' => $jobs,
            'daily_intervals' => [['canonical_interval_id' => $interval->id, 'start' => '08:30:00', 'end' => '11:00:00']]]);
        // Independent reference conflict on a day without duplicate rows.
        $this->row($period, $owner, '2026-10-03', ['daily_ocr_job_ids' => $jobs]);
        $before = DB::table('reconciliation_rows')->get()->toJson();
        DB::enableQueryLog();
        $audit = app(\App\Services\Reconciliation\ReconciliationConsistencyAuditService::class)->audit($period, null, null, null, true, 'operator-label');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', ltrim($query['query']));
        }
        $this->assertSame(2, $audit['schema_version']);
        $this->assertSame('operator-label', $audit['operator_reported_release']);
        $details = $audit['evidence']['rows'];
        $this->assertFalse($details[0]['references'][0]['matches_stored_row']);
        $this->assertTrue($details[0]['references'][0]['matches_daily_owner']);
        $this->assertFalse($details[2]['references'][0]['matches_daily_owner']);
        $this->assertSame('HUMAN_REVIEW', $details[0]['disposition']);
        $this->assertSame('07:30:00', $details[0]['daily_intervals'][0]['values']['start']);
        $this->assertStringNotContainsString('SECRET_NOTE', json_encode($audit));
        $this->assertStringNotContainsString('unchanged', json_encode($audit));
        app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame($before, DB::table('reconciliation_rows')->get()->toJson());
        $this->artisan('reconciliation:consistency-audit', ['period' => $period->id, '--release' => 'invalid-release'])->assertFailed();
        $this->artisan('reconciliation:consistency-audit', ['period' => $period->id, '--details' => true,
            '--machine' => $this->machine->id, '--from' => '2026-10-02', '--to' => '2026-10-03'])->assertSuccessful();
    }

    public function test_metadata_repair_rejects_wrong_source_date_even_when_case_and_membership_match(): void
    {
        config(['daily_photos.enabled' => true]);
        $owner = $this->assignment('2026-09-30', null, $this->b);
        $period = $this->period('2026-10');
        $this->canonical($owner, '2026-10-02');
        $job = OcrJob::first();
        $metadata = $job->daily_metadata;
        $metadata['case_materialization']['machine_assignment_id'] = 99999;
        $job->update(['daily_metadata' => $metadata, 'extracted_date' => '2026-10-03']);
        $this->row($period, $owner, '2026-10-02', ['daily_ocr_job_ids' => [$job->id]]);
        $before = DB::table('ocr_jobs')->get()->toJson();
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(1, $result['diagnostics']['reasons']['CANONICAL_OCR_CONFLICT']);
        $this->assertSame($before, DB::table('ocr_jobs')->get()->toJson());
    }

    public function test_generator_occupancy_and_next_month_replay_preserve_one_machine_day(): void
    {
        config(['daily_photos.enabled' => true]);
        $old = $this->assignment('2026-08-01', '2026-09-30 15:00:00');
        $owner = $this->assignment('2026-09-30 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $legacy = $this->row($period, $old, '2026-10-02', ['segment_start' => '15:00:00', 'segment_end' => '15:00:00', 'regular_minutes' => 210]);
        $this->canonical($owner, '2026-10-02');
        $before = $legacy->getAttributes();
        app(ReconciliationGenerator::class)->generate($period);
        app(ReconciliationGenerator::class)->generate($period);
        $this->assertSame(1, $period->rows()->whereDate('work_date', '2026-10-02')->count());
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $next = $this->period('2026-11');
        $this->canonical($owner, '2026-11-02');
        app(ReconciliationGenerator::class)->generate($next);
        $first = $next->rows()->count();
        app(ReconciliationGenerator::class)->generate($next);
        $this->assertSame($first, $next->rows()->count());
        $this->assertSame(1, $next->rows()->whereDate('work_date', '2026-11-02')->count());
    }

    public static function sameSourceMatrix(): array
    {
        $cases = [];
        foreach (['period', 'global', 'automatic'] as $mode) {
            $cases[$mode.' old rich'] = [true, true, $mode];
            $cases[$mode.' owner rich'] = [false, true, $mode];
            $cases[$mode.' no allocation'] = [true, false, $mode];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sameSourceMatrix')]
    public function test_same_ocr_redundant_sibling_preserves_the_whole_interval_bearing_row(bool $oldRich, bool $allocated, string $mode): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-30 15:00:00');
        $owner = $this->assignment('2026-09-30 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->canonical($owner, '2026-10-02');
        $jobs = OcrJob::pluck('id')->all();
        $interval = DailyPhotoInterval::first();
        $bundle = ['daily_intervals' => [['canonical_interval_id' => $interval->id, 'start_job_id' => $jobs[0],
            'end_job_id' => $jobs[1], 'start' => '07:30', 'end' => '11:00', 'start_date' => '2026-10-02', 'end_date' => '2026-10-02']]];
        if ($allocated) {
            $bundle += ['regular_minutes' => 210, 'confirmed_check_in' => '07:30:00', 'confirmed_check_out' => '11:00:00'];
        }
        $source = $this->row($period, $old, '2026-10-02', ['daily_ocr_job_ids' => $jobs] + ($oldRich ? $bundle : []));
        $target = $this->row($period, $owner, '2026-10-02', ['daily_ocr_job_ids' => array_reverse($jobs)] + ($oldRich ? [] : $bundle));
        $survivor = $oldRich ? $source : $target;
        $redundant = $oldRich ? $target : $source;
        $before = $survivor->getAttributes();
        $snapshots = [];
        foreach (['ocr_jobs', 'daily_photo_cases', 'daily_photo_case_evidence', 'daily_photo_intervals', 'zalo_attachments', 'machine_assignments', 'machine_events'] as $table) {
            $snapshots[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $service = app(ReconciliationLinkRepairService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $plan = $service->plan($period);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', ltrim($query['query']));
        }
        $this->assertSame(1, $plan['duplicates_consolidated']);
        $this->assertSame($survivor->id, $plan['actions'][0]['survivor_row_id']);
        if ($mode === 'global') {
            $global = app(\App\Services\Reconciliation\GlobalReconciliationRepairService::class);
            $user = \App\Models\User::factory()->create();
            $run = $global->start($user, $global->preview($user));
            $global->processNext();
            $result = $run->fresh()->periods[$period->id]['result'];
        } elseif ($mode === 'automatic') {
            $result = app(\App\Services\Reconciliation\AssignmentRelationshipPropagation::class)
                ->propagate($this->machine->id, '2026-09-30', null, null)['periods'][$period->id];
        } else {
            $result = $service->repair($period, null);
        }
        $this->assertSame($plan['actions'], $result['actions']);
        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame([$survivor->id], $period->rows()->pluck('id')->all());
        $this->assertSame(2, $result['diagnostics']['total_inspected']);
        $this->assertSame(2, $result['repaired'] + $result['removed'] + $result['diagnostics']['already_correct']);
        $this->assertNull($redundant->fresh());
        $this->assertSame($owner->id, $survivor->fresh()->machine_assignment_id);
        foreach (array_diff_key($before, array_flip(['machine_assignment_id', 'project_id', 'command_center_id', 'updated_at'])) as $field => $value) {
            $this->assertSame($value, $survivor->fresh()->getAttributes()[$field], $field);
        }
        foreach ($snapshots as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $merge = ActivityLog::get()->first(fn ($log) => ($log->properties['action'] ?? null) === 'SAME_OCR_REDUNDANT_SHADOW');
        $this->assertNotNull($merge);
        // Repair normalizes legacy SQLite midnight values to the MySQL DATE representation.
        $auditBefore = array_replace($before, ['work_date' => substr($before['work_date'], 0, 10)]);
        $auditAfter = (array) DB::table('reconciliation_rows')->find($survivor->id);
        $auditAfter['work_date'] = substr($auditAfter['work_date'], 0, 10);
        $this->assertEquals($auditBefore, $merge->properties['survivor_before']);
        $this->assertEquals($auditAfter, $merge->properties['survivor_after']);
        $this->assertSame([$interval->id], $merge->properties['proof']['canonical_interval_ids']);
        $this->assertTrue($merge->properties['proof']['preserve_whole_row']);
        $logs = ActivityLog::count();
        $again = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(0, $again['removed']);
        $this->assertSame(0, $again['repaired']);
        $this->assertSame($logs, ActivityLog::count());
    }

    public static function rejectedSameSource(): array
    {
        return array_map(fn ($reason) => [$reason], array_combine(
            ['hours', 'gps', 'descriptor', 'missing job', 'wrong time', 'wrong pairing', 'human', 'shared lock'],
            ['hours', 'gps', 'descriptor', 'missing job', 'wrong time', 'wrong pairing', 'human', 'shared lock']));
    }

    public static function differentZeroAllocation(): array
    {
        $cases = [];
        foreach ([[210, 0], [null, 0], [0, null]] as [$rich, $shadow]) {
            foreach ([true, false] as $oldRich) {
                $cases[] = [$rich, $shadow, $oldRich];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('differentZeroAllocation')]
    public function test_zero_and_null_allocation_conflicts_retain_both_rows(?int $richMinutes, ?int $shadowMinutes, bool $oldRich): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-30 15:00:00');
        $owner = $this->assignment('2026-09-30 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->canonical($owner, '2026-10-02');
        $jobs = OcrJob::pluck('id')->all();
        $rich = $this->row($period, $oldRich ? $old : $owner, '2026-10-02', ['daily_ocr_job_ids' => $jobs,
            'daily_intervals' => [['canonical_interval_id' => DailyPhotoInterval::first()->id]], 'regular_minutes' => $richMinutes]);
        $this->row($period, $oldRich ? $owner : $old, '2026-10-02', ['daily_ocr_job_ids' => $jobs, 'regular_minutes' => $shadowMinutes]);
        $before = DB::table('reconciliation_rows')->orderBy('id')->get()->toJson();
        $service = app(ReconciliationLinkRepairService::class);
        $plan = $service->plan($period);
        $this->assertSame(0, $plan['duplicates_consolidated']);
        $result = $service->repair($period, null);
        $this->assertSame(0, $result['removed']);
        $this->assertGreaterThan(0, $result['unresolved']);
        $this->assertArrayHasKey('DUPLICATE_PAYLOAD_CONFLICT', $result['diagnostics']['reasons']);
        $this->assertSame($before, DB::table('reconciliation_rows')->orderBy('id')->get()->toJson());
        $this->assertSame($richMinutes, $rich->fresh()->regular_minutes);
    }

    public function test_snapshot_fingerprints_ignore_reversed_sql_order_and_lock_mode(): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-30 15:00:00');
        $owner = $this->assignment('2026-09-30 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->canonical($owner, '2026-10-02');
        $this->canonical($owner, '2026-10-03');
        $this->row($period, $old, '2026-10-02', ['daily_ocr_job_ids' => OcrJob::whereDate('extracted_date', '2026-10-02')->pluck('id')->all()]);
        $this->row($period, $old, '2026-10-03', ['daily_ocr_job_ids' => OcrJob::whereDate('extracted_date', '2026-10-03')->pluck('id')->all()]);
        $service = app(ReconciliationLinkRepairService::class);
        $normal = $service->plan($period);
        $canonical = new \App\Services\Reconciliation\CanonicalAssignmentRelinker([$this->machine->id], '2026-10-01', '2026-10-31', false);
        $jobs = DB::table('ocr_jobs')->pluck('id')->all();
        DB::statement('PRAGMA reverse_unordered_selects = ON');
        try {
            $this->assertSame(array_reverse($jobs), DB::table('ocr_jobs')->pluck('id')->all());
            $reversed = new \App\Services\Reconciliation\CanonicalAssignmentRelinker([$this->machine->id], '2026-10-01', '2026-10-31', true);
            $this->assertSame($canonical->snapshotFingerprint(), $reversed->snapshotFingerprint());
            $this->assertSame($normal['snapshot_fingerprint'], $service->plan($period)['snapshot_fingerprint']);
            $result = $service->repairExpected($period, \App\Models\User::factory()->create()->id, $normal['snapshot_fingerprint'], 'stable-order-test');
            $this->assertSame(2, $result['repaired']);
        } finally {
            DB::statement('PRAGMA reverse_unordered_selects = OFF');
        }
    }

    public function test_string_zero_is_populated_and_equal_allocation_bundles_remain_accepted(): void
    {
        $classifier = new \App\Services\Reconciliation\ReconciliationDuplicateClassifier;
        foreach ([[210, '0'], [null, '0'], ['0', null]] as [$a, $b]) {
            $rich = (object) ['daily_intervals' => '[{"canonical_interval_id":1}]', 'regular_minutes' => $a];
            $shadow = (object) ['daily_intervals' => null, 'regular_minutes' => $b];
            $this->assertFalse($classifier->sameSourceShadow($rich, $shadow));
        }
        foreach ([null, 0, '0', 210] as $value) {
            $rich = (object) ['daily_intervals' => '[{"canonical_interval_id":1}]', 'regular_minutes' => $value];
            $shadow = (object) ['daily_intervals' => null, 'regular_minutes' => $value];
            $this->assertTrue($classifier->sameSourceShadow($rich, $shadow));
        }
    }

    public static function snapshotChanges(): array
    {
        return [['job time'], ['job protection'], ['row zero to null'], ['shared protection value']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('snapshotChanges')]
    public function test_changed_values_and_protection_still_reject_stale_snapshot(string $change): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-30 15:00:00');
        $owner = $this->assignment('2026-09-30 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->canonical($owner, '2026-10-02');
        $jobs = OcrJob::pluck('id')->all();
        $row = $this->row($period, $old, '2026-10-02', ['regular_minutes' => 0, 'daily_ocr_job_ids' => $jobs]);
        if ($change === 'shared protection value') {
            $protectedPeriod = ReconciliationPeriod::create(['name' => 'Shared', 'type' => 'WEEKLY',
                'date_from' => '2026-10-01', 'date_to' => '2026-10-07', 'status' => 'CONFIRMED']);
            $protected = $this->row($protectedPeriod, $owner, '2026-10-02', ['daily_ocr_job_ids' => $jobs, 'reviewed_at' => '2026-10-04 12:00:00']);
        }
        $service = app(ReconciliationLinkRepairService::class);
        $plan = $service->plan($period);
        $canonical = new \App\Services\Reconciliation\CanonicalAssignmentRelinker([$this->machine->id], '2026-10-01', '2026-10-31', false);
        $fingerprint = $canonical->snapshotFingerprint();
        if ($change === 'job time') {
            OcrJob::whereKey($jobs[0])->update(['extracted_time' => '08:00:00']);
        } elseif ($change === 'job protection') {
            OcrJob::whereKey($jobs[0])->update(['machine_resolution_method' => 'HUMAN']);
        } elseif ($change === 'row zero to null') {
            $row->update(['regular_minutes' => null]);
        } else {
            $protected->update(['reviewed_at' => '2026-10-05 12:00:00']);
        }
        if ($change !== 'row zero to null') {
            $changed = new \App\Services\Reconciliation\CanonicalAssignmentRelinker([$this->machine->id], '2026-10-01', '2026-10-31', false);
            $this->assertNotSame($fingerprint, $changed->snapshotFingerprint());
        }
        $this->assertNotSame($plan['snapshot_fingerprint'], $service->plan($period)['snapshot_fingerprint']);
        $before = DB::table('reconciliation_rows')->orderBy('id')->get()->toJson();
        $logs = ActivityLog::count();
        try {
            $service->repairExpected($period, \App\Models\User::factory()->create()->id, $plan['snapshot_fingerprint'], 'stale-test');
            $this->fail('Changed snapshot accepted');
        } catch (\DomainException $exception) {
            $this->assertSame('STALE_PREVIEW', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('reconciliation_rows')->orderBy('id')->get()->toJson());
        $this->assertSame($logs, ActivityLog::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedSameSource')]
    public function test_same_ocr_ids_alone_never_authorize_consolidation(string $mutation): void
    {
        $old = $this->assignment('2026-08-01', '2026-09-30 15:00:00');
        $owner = $this->assignment('2026-09-30 15:00:00', null, $this->b);
        $period = $this->period('2026-10');
        $this->canonical($owner, '2026-10-02');
        $jobs = OcrJob::pluck('id')->all();
        $interval = DailyPhotoInterval::first();
        $part = ['canonical_interval_id' => $interval->id, 'start_job_id' => $jobs[0], 'end_job_id' => $jobs[1], 'start' => '07:30', 'end' => '11:00'];
        $rich = $this->row($period, $old, '2026-10-02', ['daily_ocr_job_ids' => $jobs, 'daily_intervals' => [$part], 'regular_minutes' => 210]);
        $shadow = $this->row($period, $owner, '2026-10-02', ['daily_ocr_job_ids' => $jobs]);
        if ($mutation === 'hours') {
            $shadow->update(['regular_minutes' => 211]);
        } elseif ($mutation === 'gps') {
            $shadow->update(['gps_check_in' => '09:00:00']);
        } elseif ($mutation === 'descriptor') {
            $shadow->update(['notes' => 'Independent notes']);
        } elseif ($mutation === 'missing job') {
            $rich->update(['daily_ocr_job_ids' => [...$jobs, 999999]]);
            $shadow->update(['daily_ocr_job_ids' => [...$jobs, 999999]]);
        } elseif ($mutation === 'wrong time' || $mutation === 'wrong pairing') {
            $part[$mutation === 'wrong time' ? 'start' : 'start_job_id'] = $mutation === 'wrong time' ? '08:30' : $jobs[1];
            $rich->update(['daily_intervals' => [$part]]);
        } elseif ($mutation === 'human') {
            OcrJob::whereKey($jobs[0])->update(['machine_resolution_method' => 'HUMAN']);
        } else {
            $locked = ReconciliationPeriod::create(['name' => 'Shared locked', 'type' => 'WEEKLY',
                'date_from' => '2026-10-01', 'date_to' => '2026-10-07', 'status' => 'CONFIRMED']);
            $this->row($locked, $owner, '2026-10-02', ['daily_ocr_job_ids' => $jobs]);
        }
        $before = DB::table('reconciliation_rows')->orderBy('id')->get()->toJson();
        $service = app(ReconciliationLinkRepairService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $plan = $service->plan($period);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', ltrim($query['query']));
        }
        $this->assertSame(0, $plan['duplicates_consolidated']);
        $result = $service->repair($period, null);
        $this->assertSame(0, $result['removed']);
        $this->assertGreaterThan(0, $result['unresolved']);
        $this->assertSame($before, DB::table('reconciliation_rows')->orderBy('id')->get()->toJson());
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
