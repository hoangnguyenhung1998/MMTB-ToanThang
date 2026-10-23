<?php

namespace Tests\Feature;

use App\Models\DailyPhotoCase;
use App\Models\DailyPhotoRecoveryRun;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\OcrJob;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Models\ZaloSenderMachineMapping;
use App\Services\DailyPhotoBacklogService;
use App\Services\DailyPhotoPairingService;
use App\Services\DailyPhotoRecoveryRunService;
use App\Services\Reconciliation\DailyPhotoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyPhotoRecoveryRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_photos.enabled' => true, 'ocr.max_attempts' => 3]);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function fixture(string $sender = 'fixture-sender', array $extra = []): OcrJob
    {
        $machine = Machine::firstOrCreate(['asset_code' => 'VT-XX5259'], ['chassis_no' => 'FIXTURE-'.Str::uuid(), 'company' => 'VINCONS', 'status' => 'ACTIVE']);
        $job = $this->job($sender, 'EXCEPTION', null, [
            'extracted_date' => '2026-09-10', 'extracted_time' => '06:15:00', 'attempts' => 1,
            'exceptions' => ['MISSING_ASSET_CODE'], ...$extra,
        ]);
        if (! ZaloSenderMachineMapping::where('sender_id', $sender)->exists()) {
            $this->mapping($job, $machine);
        }

        return $job;
    }

    private function drain(string $id): DailyPhotoRecoveryRun
    {
        $service = app(DailyPhotoRecoveryRunService::class);
        for ($steps = 0; $steps < 500 && $service->processNext($id); $steps++) {
        }
        $this->assertLessThan(500, $steps, 'Worker must terminate');

        return DailyPhotoRecoveryRun::findOrFail($id);
    }

    public function test_post_is_lightweight_deduplicated_and_progress_is_private(): void
    {
        $job = $this->fixture();
        $actor = User::factory()->create();
        $this->mock(DailyPhotoBacklogService::class)->shouldNotReceive('recover', 'analyse', 'report');
        $this->mock(DailyPhotoPairingService::class)->shouldNotReceive('recomputeMany');
        $this->mock(DailyPhotoSyncService::class)->shouldNotReceive('sync');
        $id = (string) Str::uuid();
        $filters = ['sender_id' => 'fixture-sender', 'request_id' => $id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30'];
        $this->actingAs($actor)->post(route('daily-photos.recover'), $filters)->assertRedirect(route('daily-photos.recovery.show', $id));
        $this->post(route('daily-photos.recover'), $filters)->assertRedirect(route('daily-photos.recovery.show', $id));
        $this->assertDatabaseCount('daily_photo_recovery_runs', 1);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
        $this->get(route('daily-photos.recovery.show', $id))->assertOk()->assertSee('Bạn có thể đóng trình duyệt');
        $this->getJson(route('daily-photos.recovery.show', $id))->assertOk()->assertJsonPath('status', 'QUEUED')
            ->assertJsonPath('filters.date_from', '2026-09-01');
        $this->actingAs(User::factory()->create())->getJson(route('daily-photos.recovery.show', $id))->assertForbidden();
        $this->post(route('daily-photos.recovery.retry', $id))->assertForbidden();
        auth()->logout();
        $this->getJson(route('daily-photos.recovery.show', $id))->assertUnauthorized();
    }

    public function test_91_image_fixture_manual_and_protected_survive_and_restart_completes_downstream(): void
    {
        for ($i = 0; $i < 89; $i++) {
            $this->fixture();
        }
        for ($i = 0; $i < 2; $i++) {
            $this->fixture(extra: ['extracted_time' => null, 'ocr_retry_attempts' => 1]);
        }
        $protected = $this->fixture(extra: ['review_status' => 'APPROVED', 'reviewed_at' => now()]);
        $before = $protected->getAttributes();
        $run = app(DailyPhotoRecoveryRunService::class)->start(User::factory()->create(), ['sender_id' => 'fixture-sender']);
        app(DailyPhotoRecoveryRunService::class)->processNext($run->id);
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
        $this->assertDatabaseHas('daily_photo_recovery_work', ['run_id' => $run->id, 'stage' => 'PAIR', 'done' => false]);
        $this->assertNull(DailyPhotoCase::first()->pairing_computed_at);
        // Reconstruct service: durable state, no browser/request-memory dependency.
        app()->forgetInstance(DailyPhotoRecoveryRunService::class);
        $finished = $this->drain($run->id);
        $this->assertSame('COMPLETED', $finished->status);
        $this->assertSame(89, $finished->counters['recovered']);
        $this->assertSame(2, $finished->counters['manual']);
        $this->assertSame(1, $finished->counters['protected']);
        $this->assertSame($before, $protected->fresh()->getAttributes());
        $this->assertDatabaseCount('daily_photo_case_evidence', 89);
        $this->assertNotNull(DailyPhotoCase::first()->pairing_computed_at);
        $this->assertSame(0, DB::table('daily_photo_recovery_work')->where('done', false)->count());
        $again = app(DailyPhotoRecoveryRunService::class)->start(User::factory()->create(), ['sender_id' => 'fixture-sender']);
        $this->assertSame(0, $this->drain($again->id)->counters['recovered']);
        $this->assertDatabaseCount('daily_photo_case_evidence', 89);
    }

    public function test_retry_queue_is_distinct_from_completed_ocr_and_dispatch_state_is_revalidated(): void
    {
        $retry = $this->fixture(extra: ['extracted_time' => null]);
        $protected = $this->fixture();
        $outside = $this->fixture('other-sender');
        $run = app(DailyPhotoRecoveryRunService::class)->start(User::factory()->create(), ['sender_id' => 'fixture-sender']);
        $protected->update(['review_status' => 'CORRECTED', 'reviewed_at' => now()]);
        $late = $this->fixture();
        $finished = $this->drain($run->id);
        $this->assertSame(1, $finished->counters['queued_retry']);
        $this->assertSame(0, $finished->counters['recovered']);
        $this->assertSame('RETRY', $retry->fresh()->status);
        $this->assertSame('EXCEPTION', $outside->fresh()->status);
        $this->assertSame('EXCEPTION', $late->fresh()->status);
        $this->assertSame('CORRECTED', $protected->fresh()->review_status);
    }

    public function test_pairing_failure_rolls_back_has_bounded_retries_and_resumes_without_duplicate_counters(): void
    {
        $this->fixture();
        $service = app(DailyPhotoRecoveryRunService::class);
        $run = $service->start(User::factory()->create(), ['sender_id' => 'fixture-sender']);
        $service->processNext($run->id); // Recovery + obligations committed.
        $service->processNext($run->id); // Discovery complete.
        $this->mock(DailyPhotoPairingService::class)->shouldReceive('recomputeMany')->times(3)
            ->andReturnUsing(function () {
                DailyPhotoCase::first()->update(['status' => 'READY']);
                throw new \RuntimeException('synthetic pairing failure');
            });
        app()->forgetInstance(DailyPhotoRecoveryRunService::class);
        $failed = $this->drain($run->id);
        $this->assertSame('FAILED', $failed->status);
        $this->assertSame(3, $failed->failures);
        $this->assertSame('COLLECTING', DailyPhotoCase::first()->status);
        $this->assertSame(1, $failed->counters['recovered']);
        $this->assertSame(0, $failed->counters['paired']);
        app()->forgetInstance(DailyPhotoPairingService::class);
        app()->forgetInstance(DailyPhotoRecoveryRunService::class);
        app(DailyPhotoRecoveryRunService::class)->retry($failed);
        $finished = $this->drain($run->id);
        $this->assertSame('COMPLETED', $finished->status);
        $this->assertSame(1, $finished->counters['recovered']);
        $this->assertSame(1, $finished->counters['paired']);
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
    }

    public function test_legacy_completed_preview_is_read_only_and_repair_never_requeues_ocr(): void
    {
        $job = $this->fixture();
        app(DailyPhotoBacklogService::class)->recover(['job' => $job->id], fn () => null);
        $job->refresh();
        $before = array_intersect_key($job->getAttributes(), array_flip(['status', 'extracted_date', 'extracted_time', 'raw_text', 'attempts', 'ocr_retry_attempts', 'review_status']));
        $service = app(DailyPhotoRecoveryRunService::class);
        $this->assertSame(['PAIRING_NOT_COMPUTED_AFTER_EVIDENCE'], $service->diagnose($job)['reasons']);
        $this->assertSame($before, array_intersect_key($job->fresh()->getAttributes(), $before));
        $run = $service->start(User::factory()->create(), ['sender_id' => 'fixture-sender', 'job' => $job->id], mode: 'DOWNSTREAM');
        $this->assertSame('COMPLETED', $this->drain($run->id)->status);
        $this->assertFalse($service->diagnose($job->fresh())['missing']);
        $this->assertSame($before, array_intersect_key($job->fresh()->getAttributes(), $before));
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
    }

    private function periodFixture(OcrJob $job): ReconciliationPeriod
    {
        $machine = Machine::where('asset_code', 'VT-XX5259')->firstOrFail();
        MachineAssignment::create(['machine_id' => $machine->id,
            'project_id' => \App\Models\Project::create(['name' => 'Fixture project'])->id,
            'command_center_id' => \App\Models\CommandCenter::create(['name' => 'Fixture BCH'])->id,
            'time_in' => '2026-09-01 00:00:00']);

        return ReconciliationPeriod::create(['name' => 'Fixture September', 'type' => 'MONTHLY',
            'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'status' => 'GENERATED']);
    }

    public function test_sync_failure_and_restart_commit_one_period_at_a_time(): void
    {
        $job = $this->fixture();
        $period = $this->periodFixture($job);
        $second = ReconciliationPeriod::create(['name' => 'Fixture weekly', 'type' => 'WEEKLY',
            'date_from' => '2026-09-07', 'date_to' => '2026-09-13', 'status' => 'GENERATED']);
        $service = app(DailyPhotoRecoveryRunService::class);
        $run = $service->start(User::factory()->create(), ['sender_id' => 'fixture-sender']);
        $service->processNext($run->id);
        $service->processNext($run->id);
        $service->processNext($run->id); // Pairing.
        $service->processNext($run->id); // First period only.
        $this->assertSame(1, $run->fresh()->counters['synced']);
        $this->assertSame(1, $period->rows()->count());
        $this->assertSame(0, $second->rows()->count());
        $real = app(DailyPhotoSyncService::class);
        $this->mock(DailyPhotoSyncService::class)->shouldReceive('sync')->times(3)
            ->andReturnUsing(function (...$args) use ($real) {
                $real->sync(...$args);
                throw new \RuntimeException('after actual sync');
            });
        app()->forgetInstance(DailyPhotoRecoveryRunService::class);
        $failed = $this->drain($run->id);
        $this->assertSame('FAILED', $failed->status);
        $this->assertSame(1, $failed->counters['synced']);
        $this->assertSame(0, $second->rows()->count()); // Data and progress rolled back together.
        app()->forgetInstance(DailyPhotoSyncService::class);
        app()->forgetInstance(DailyPhotoRecoveryRunService::class);
        app(DailyPhotoRecoveryRunService::class)->retry($failed);
        $finished = $this->drain($run->id);
        $this->assertSame('COMPLETED', $finished->status);
        $this->assertSame(2, $finished->counters['synced']);
        $this->assertSame(1, $second->rows()->count());
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
    }

    public function test_changed_mapping_scope_and_reason_filters_are_rechecked_at_execution(): void
    {
        $job = $this->fixture();
        $this->periodFixture($job);
        $assignment = MachineAssignment::first();
        $other = $this->fixture('other-sender');
        $service = app(DailyPhotoRecoveryRunService::class);
        $actor = User::factory()->create();
        $run = $service->start($actor, ['sender_id' => 'fixture-sender', 'command_center_id' => $assignment->command_center_id,
            'project_id' => $assignment->project_id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
        $assignment->update(['project_id' => \App\Models\Project::create(['name' => 'Changed after dispatch'])->id]);
        $this->assertSame(0, $this->drain($run->id)->counters['recovered']);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
        $filtered = $service->start($actor, ['sender_id' => 'fixture-sender', 'reason' => 'MACHINE_AMBIGUOUS']);
        $this->assertSame(0, $this->drain($filtered->id)->counters['recovered']);
        $Z = ZaloSenderMachineMapping::where('sender_id', 'fixture-sender')->first();
        $Z->delete();
        $unmapped = $service->start($actor, ['sender_id' => 'fixture-sender']);
        $this->assertSame(0, $this->drain($unmapped->id)->counters['recovered']);
        $this->assertSame('EXCEPTION', $other->fresh()->status);
    }

    public function test_abandoned_claim_cannot_be_stolen_early_and_three_crashes_stop_the_run(): void
    {
        $this->fixture();
        $service = app(DailyPhotoRecoveryRunService::class);
        $run = $service->start(User::factory()->create(), ['sender_id' => 'fixture-sender']);
        $run->update(['status' => 'RUNNING', 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(2), 'failures' => 1]);
        $this->assertFalse($service->processNext($run->id));
        $this->assertSame(0, $run->fresh()->counters['examined']);
        $this->travel(3)->minutes();
        $this->assertTrue($service->processNext($run->id));
        $this->assertSame(1, $run->fresh()->counters['recovered']);
        $this->assertSame(0, $run->fresh()->failures);
        $run->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->subMinute(), 'failures' => 3]);
        $this->assertFalse($service->processNext($run->id));
        $this->assertSame('FAILED', $run->fresh()->status);
        $this->assertSame('ABANDONED_WORK_UNIT', $run->fresh()->error_code);
        $service->retry($run->fresh());
        $this->assertSame('COMPLETED', $this->drain($run->id)->status);
        $this->travelBack();
    }

    public function test_legacy_sync_only_preview_has_signature_proof_and_is_select_only(): void
    {
        $job = $this->fixture();
        $period = $this->periodFixture($job);
        app(DailyPhotoBacklogService::class)->recover(['job' => $job->id]);
        $row = $period->rows()->sole();
        $row->update(['evidence_signature' => null]); // Model old recovery committed, downstream incomplete.
        $before = $row->fresh()->getAttributes();
        $service = app(DailyPhotoRecoveryRunService::class);
        $proof = $service->diagnose($job->fresh());
        $this->assertContains('STALE_EVIDENCE_SIGNATURE:'.$row->id, $proof['reasons']);
        $this->assertNotEmpty(collect($proof['evidence'])->firstWhere('row_id', $row->id)['expected_signature']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $actor = User::factory()->create();
        $this->artisan('ocr:daily-backlog-recover', ['--dry-run' => true, '--downstream' => true, '--job' => $job->id])->assertExitCode(0);
        $this->assertDatabaseCount('daily_photo_recovery_runs', 0);
        $this->artisan('ocr:daily-backlog-recover', ['--execute' => true, '--downstream' => true, '--job' => $job->id, '--actor' => $actor->id])->assertExitCode(0);
        $this->assertSame('COMPLETED', $this->drain(DailyPhotoRecoveryRun::sole()->id)->status);
        $this->assertFalse($service->diagnose($job->fresh())['missing']);
        $this->artisan('ocr:daily-backlog-recover', ['--execute' => true, '--downstream' => true, '--job' => $job->id, '--actor' => $actor->id])->assertExitCode(0);
        $this->assertDatabaseCount('daily_photo_recovery_runs', 1); // Second execution is a no-op.
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
        $this->assertDatabaseCount('reconciliation_rows', 1);
        $this->assertSame('COMPLETED', $job->fresh()->status);
    }

    public function test_pairing_preview_detects_evidence_added_in_the_same_timestamp_second(): void
    {
        $this->freezeTime();
        $first = $this->fixture();
        app(DailyPhotoBacklogService::class)->recover(['job' => $first->id]);
        $second = $this->fixture(extra: ['extracted_time' => '17:15:00']);
        app(DailyPhotoBacklogService::class)->recover(['job' => $second->id], fn () => null);
        $proof = app(DailyPhotoRecoveryRunService::class)->diagnose($second->fresh());
        $this->assertContains('PAIRING_NOT_COMPUTED_AFTER_EVIDENCE', $proof['reasons']);
        $this->assertSame(1, $proof['evidence'][0]['paired_evidence_count']);
    }

    public function test_worker_is_registered_in_the_minute_scheduler(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('ocr:process-daily-recovery-runs --steps=10')->assertExitCode(0);
    }

    public function test_period_becoming_protected_cannot_drop_a_persisted_sync_obligation(): void
    {
        $job = $this->fixture();
        $period = $this->periodFixture($job);
        $service = app(DailyPhotoRecoveryRunService::class);
        $run = $service->start(User::factory()->create(), ['sender_id' => 'fixture-sender']);
        $service->processNext($run->id);
        $payload = json_decode(DB::table('daily_photo_recovery_work')->where('stage', 'SYNC')->sole()->payload, true);
        $this->assertSame([$period->id], $payload['period_ids']);
        $period->update(['status' => 'CONFIRMED']);
        $failed = $this->drain($run->id);
        $this->assertSame('FAILED', $failed->status);
        $this->assertSame('SYNC_PERIOD_BECAME_PROTECTED', $failed->error_code);
        $this->assertSame($period->id, $failed->error_context['period_id']);
        $this->assertSame(0, $failed->counters['synced']);
        $this->assertDatabaseHas('daily_photo_recovery_work', ['run_id' => $run->id, 'stage' => 'SYNC', 'done' => false]);
        $this->assertSame(0, $period->rows()->count());
        $period->update(['status' => 'GENERATED']); // Model authorized operator resolution in fixture only.
        $service->retry($failed);
        $this->assertSame('COMPLETED', $this->drain($run->id)->status);
        $this->assertSame(1, $period->rows()->count());
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
    }

    public function test_recovery_move_syncs_old_and_new_machine_days(): void
    {
        $this->assertMovedEvidenceSyncsBothScopes('RECOVERY');
    }

    public function test_downstream_move_syncs_old_and_new_machine_days(): void
    {
        $this->assertMovedEvidenceSyncsBothScopes('DOWNSTREAM');
    }

    public function test_downstream_move_preserves_reviewed_old_row(): void
    {
        $this->assertMovedEvidenceSyncsBothScopes('DOWNSTREAM', true);
    }

    private function assertMovedEvidenceSyncsBothScopes(string $mode, bool $protectedRow = false): void
    {
        $job = $this->fixture();
        $period = $this->periodFixture($job);
        $targetMachine = Machine::where('asset_code', 'VT-XX5259')->sole();
        $oldMachine = Machine::create(['asset_code' => 'VT-OLD5259', 'chassis_no' => 'MOVE-FIXTURE',
            'company' => 'VINCONS', 'status' => 'ACTIVE']);
        $assignment = MachineAssignment::sole();
        MachineAssignment::create(['machine_id' => $oldMachine->id, 'project_id' => $assignment->project_id,
            'command_center_id' => $assignment->command_center_id, 'time_in' => '2026-09-01 00:00:00']);
        $job->update(['status' => 'COMPLETED', 'machine_id' => $oldMachine->id, 'extracted_date' => '2026-09-09']);
        app(\App\Services\DailyPhotoCaseService::class)->materialize($job);
        app(DailyPhotoSyncService::class)->sync($period);
        $oldCase = $job->fresh()->daily_photo_case_id;
        $oldRow = $period->rows()->where('machine_id', $oldMachine->id)->sole();
        $this->assertSame([$job->id], $oldRow->daily_ocr_job_ids);
        if ($protectedRow) {
            $oldRow->update(['reviewed_at' => now(), 'status' => 'REVIEWED']);
        }
        $oldPayload = $oldRow->fresh()->only(['daily_ocr_job_ids', 'regular_minutes', 'evidence_signature', 'reviewed_at', 'status']);
        $job->update(['status' => $mode === 'RECOVERY' ? 'EXCEPTION' : 'COMPLETED',
            'review_status' => 'PENDING', 'machine_id' => $mode === 'RECOVERY' ? null : $targetMachine->id,
            'daily_photo_case_id' => $mode === 'DOWNSTREAM' ? null : $oldCase,
            'extracted_date' => '2026-09-10', 'machine_resolution_metadata' => ['backlog_recovered_at' => now()->toISOString()]]);
        $service = app(DailyPhotoRecoveryRunService::class);
        $run = $service->start(User::factory()->create(), ['sender_id' => 'fixture-sender'], mode: $mode);
        $service->processNext($run->id); // Commit movement and both scopes before pairing/sync.
        $this->assertDatabaseHas('daily_photo_recovery_work', ['run_id' => $run->id,
            'work_key' => 'day:'.$oldMachine->id.'|2026-09-09', 'done' => false]);
        $this->assertDatabaseHas('daily_photo_recovery_work', ['run_id' => $run->id,
            'work_key' => 'day:'.$targetMachine->id.'|2026-09-10', 'done' => false]);
        $this->assertDatabaseHas('daily_photo_recovery_work', ['run_id' => $run->id, 'work_key' => 'case:'.$oldCase]);
        $this->assertSame([$job->id], $oldRow->fresh()->daily_ocr_job_ids);
        app()->forgetInstance(DailyPhotoRecoveryRunService::class); // Restart from committed obligations.
        $real = app(DailyPhotoSyncService::class);
        $this->mock(DailyPhotoSyncService::class)->shouldReceive('sync')->times(3)
            ->andReturnUsing(function (...$args) use ($real) {
                $real->sync(...$args);
                throw new \RuntimeException('after moved evidence sync');
            });
        $failed = $this->drain($run->id);
        $this->assertSame('FAILED', $failed->status);
        $this->assertSame(0, $failed->counters['synced']);
        $this->assertSame([$job->id], $oldRow->fresh()->daily_ocr_job_ids);
        $this->assertSame(2, DB::table('daily_photo_recovery_work')->where('run_id', $run->id)->where('stage', 'SYNC')->where('done', false)->count());
        app()->forgetInstance(DailyPhotoSyncService::class);
        app()->forgetInstance(DailyPhotoRecoveryRunService::class);
        app(DailyPhotoRecoveryRunService::class)->retry($failed);
        $finished = $this->drain($run->id);
        $this->assertSame('COMPLETED', $finished->status);
        if ($protectedRow) {
            $this->assertEquals($oldPayload, $oldRow->fresh()->only(array_keys($oldPayload)));
            $this->assertTrue($oldRow->fresh()->has_evidence_changes);
        } else {
            $this->assertSame([], $oldRow->fresh()->daily_ocr_job_ids);
            // Existing empty canonical case stays COLLECTING; sync labels it DAILY_PARTIAL.
            $this->assertSame('DAILY_PARTIAL', $oldRow->fresh()->evidence_status);
            $this->assertNull($oldRow->fresh()->ocr_check_in_raw);
        }
        $this->assertSame([$job->id], $period->rows()->where('machine_id', $targetMachine->id)->sole()->daily_ocr_job_ids);
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
        $this->assertSame(2, $finished->counters['synced']);
    }

    public function test_downstream_reason_filters_preview_cli_dispatch_and_execution(): void
    {
        $job = $this->fixture();
        app(DailyPhotoBacklogService::class)->recover(['job' => $job->id], fn () => null);
        $job->refresh()->update(['exceptions' => ['MISSING_TIME']]);
        $service = app(DailyPhotoRecoveryRunService::class);
        $actor = User::factory()->create();
        $outside = ['sender_id' => 'fixture-sender', 'job' => $job->id, 'reason' => 'MACHINE_AMBIGUOUS'];
        $inside = [...$outside, 'reason' => 'CAPTURE_TIME_MISSING'];
        $this->assertFalse($service->diagnose($job->fresh(), $outside)['missing']);
        $this->assertTrue($service->diagnose($job->fresh(), $inside)['missing']);
        $this->artisan('ocr:daily-backlog-recover', ['--execute' => true, '--downstream' => true,
            '--job' => $job->id, '--actor' => $actor->id, '--reason' => 'MACHINE_AMBIGUOUS'])->assertExitCode(0);
        $this->assertDatabaseCount('daily_photo_recovery_runs', 0);
        $excluded = $service->start($actor, $outside, mode: 'DOWNSTREAM');
        $this->assertSame(0, $this->drain($excluded->id)->counters['downstream_repaired']);
        $this->assertNull(DailyPhotoCase::sole()->pairing_computed_at);
        $changed = $service->start($actor, $inside, mode: 'DOWNSTREAM');
        $job->update(['exceptions' => null]); // Current reason disappears after dispatch.
        $this->assertSame(0, $this->drain($changed->id)->counters['downstream_repaired']);
        $this->assertNull(DailyPhotoCase::sole()->pairing_computed_at);
        $job->update(['exceptions' => ['MISSING_TIME']]);
        $this->artisan('ocr:daily-backlog-recover', ['--execute' => true, '--downstream' => true,
            '--job' => $job->id, '--actor' => $actor->id, '--reason' => 'CAPTURE_TIME_MISSING'])->assertExitCode(0);
        $included = DailyPhotoRecoveryRun::where('status', 'QUEUED')->sole();
        $this->assertSame(1, $this->drain($included->id)->counters['downstream_repaired']);
        $this->assertNotNull(DailyPhotoCase::sole()->pairing_computed_at);
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
    }

    private function job(string $sender, string $status, ?string $asset, array $extra = []): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'auto-recovery',
            'message_id' => (string) Str::uuid(),
            'sender_id' => $sender,
            'sender_name' => $sender,
            'sent_at' => '2026-09-10 06:00:00',
            'received_at' => '2026-09-10 06:01:00',
            'status' => 'STORED',
        ]);
        $path = 'test/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'image');
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'image.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', $path),
            'mime_type' => 'image/jpeg',
            'byte_size' => 5,
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'document_type' => 'DAILY_TIMEMARK',
            'status' => $status,
            'review_status' => 'PENDING',
            'asset_code' => $asset,
            'observed_asset_code' => $asset,
            ...$extra,
        ])->fresh(['attachment.message']);
    }

    private function mapping(OcrJob $job, Machine $machine): ZaloSenderMachineMapping
    {
        return ZaloSenderMachineMapping::query()->create([
            'sender_id' => $job->attachment->message->sender_id,
            'active_sender_id' => $job->attachment->message->sender_id,
            'machine_id' => $machine->id,
            'valid_from' => $job->attachment->message->received_at->copy()->subMinute(),
            'source' => 'MANUAL_CORRECTION',
        ]);
    }
}
