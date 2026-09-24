<?php

namespace Tests\Feature;

use App\Models\DailyPhotoCase;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyPhotoManualRetryService;
use App\Services\OcrJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyPhotoManualRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'daily_photos.enabled' => true,
            'ocr.max_attempts' => 3,
            'ocr.minimum_confidence' => 0.8,
        ]);
        Storage::fake('local');
    }

    public function test_ui_manual_daily_photo_with_source_is_eligible_and_dry_run_is_read_only(): void
    {
        $job = $this->manualJob('eligible');
        $before = $job->fresh()->getAttributes();

        $preview = app(DailyPhotoManualRetryService::class)->preview();

        $this->assertSame(1, $preview['total_manual_considered']);
        $this->assertSame(1, $preview['eligible_reocr']);
        $this->assertSame(0, $preview['requeued']);
        $this->assertSame('YES', $preview['samples'][0]['eligible']);
        $this->assertSame($before, $job->fresh()->getAttributes());

        $this->artisan('ocr:daily-manual-retry --dry-run --sample-limit=1')->assertSuccessful();
        $this->assertSame($before, $job->fresh()->getAttributes());
    }

    public function test_execute_requeues_full_ocr_and_existing_worker_claim_flow_receives_it_even_after_max_attempts(): void
    {
        $job = $this->manualJob('claimable');

        $result = app(DailyPhotoManualRetryService::class)->execute();
        $requeued = $job->fresh();

        $this->assertSame(1, $result['requeued']);
        $this->assertSame('RETRY', $requeued->status);
        $this->assertSame(3, $requeued->attempts);
        $this->assertNull($requeued->ocr_retry_reason);
        $this->assertSame(1, $requeued->ocr_retry_attempts);
        $this->assertTrue(data_get($requeued->daily_metadata, 'manual_reocr.claimable'));
        $this->assertSame('legacy raw OCR', data_get($requeued->daily_metadata, 'manual_reocr.previous_state.raw_text'));

        $claimed = app(OcrJobService::class)->claim('phase-16-10-9-worker', ['DAILY_TIMEMARK']);

        $this->assertSame($job->id, $claimed?->id);
        $this->assertSame(4, $claimed?->attempts);
        $this->assertFalse(data_get($claimed?->daily_metadata, 'manual_reocr.claimable'));
        $this->assertSame([], array_values(array_filter(explode(',', (string) $claimed?->ocr_retry_reason))));
    }

    public function test_execute_command_requeues_but_does_not_run_ocr_on_laravel(): void
    {
        $job = $this->manualJob('command-execute');

        $this->artisan('ocr:daily-manual-retry --execute --sample-limit=0')->assertSuccessful();

        $this->assertSame('RETRY', $job->fresh()->status);
        $this->assertTrue(data_get($job->fresh()->daily_metadata, 'manual_reocr.claimable'));
        $this->assertDatabaseCount('ocr_processing_runs', 0);
    }

    public function test_completed_manual_reocr_preserves_provenance_and_is_not_queued_again_when_still_unresolved(): void
    {
        $job = $this->manualJob('anti-loop');
        app(DailyPhotoManualRetryService::class)->execute();
        $claimed = app(OcrJobService::class)->claim('worker-1', ['DAILY_TIMEMARK']);

        $completed = app(OcrJobService::class)->complete($claimed, [
            'worker_id' => 'worker-1',
            'attempt' => $claimed->attempts,
            'confidence' => 0.99,
            'raw_text' => 'new phase 16.10.9 OCR still unresolved',
            'candidate_metadata' => [],
        ]);

        $this->assertSame('EXCEPTION', $completed->status);
        $this->assertSame('EXCEPTION', data_get($completed->daily_metadata, 'manual_reocr.result_status'));
        $this->assertNotNull(data_get($completed->daily_metadata, 'manual_reocr.completed_at'));
        $this->assertSame('legacy raw OCR', data_get($completed->daily_metadata, 'manual_reocr.previous_state.raw_text'));

        $preview = app(DailyPhotoManualRetryService::class)->preview();
        $second = app(DailyPhotoManualRetryService::class)->execute();
        $this->assertSame(1, $preview['already_reocr_attempted_skipped']);
        $this->assertSame(0, $preview['eligible_reocr']);
        $this->assertSame(0, $second['requeued']);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
    }

    public function test_execute_twice_does_not_requeue_the_same_batch(): void
    {
        $job = $this->manualJob('twice');

        $first = app(DailyPhotoManualRetryService::class)->execute();
        $afterFirst = $job->fresh()->getAttributes();
        $second = app(DailyPhotoManualRetryService::class)->execute();

        $this->assertSame(1, $first['requeued']);
        $this->assertSame(0, $second['requeued']);
        $this->assertSame($afterFirst, $job->fresh()->getAttributes());
    }

    public function test_protected_human_reviewed_and_confirmed_states_are_unchanged_by_preview_and_execute(): void
    {
        $human = $this->manualJob('human');
        $human->update([
            'machine_resolution_method' => 'HUMAN',
            'machine_resolution_metadata' => ['human_resolution' => ['resolved_by' => 42]],
        ]);
        $reviewed = $this->manualJob('reviewed');
        $reviewed->update(['review_status' => 'APPROVED', 'reviewed_at' => now()]);
        $corrected = $this->manualJob('corrected');
        $corrected->update(['review_status' => 'CORRECTED', 'reviewed_at' => now()]);
        $snapshots = OcrJob::query()->whereKey([$human->id, $reviewed->id, $corrected->id])
            ->orderBy('id')->get()->map->getAttributes();

        $preview = app(DailyPhotoManualRetryService::class)->preview();
        $this->assertSame(3, $preview['protected_skipped']);
        $this->assertSame(2, $preview['human_corrected_skipped']);
        $this->assertSame(1, $preview['reviewed_confirmed_skipped']);
        $this->assertEquals($snapshots, OcrJob::query()->whereKey([$human->id, $reviewed->id, $corrected->id])->orderBy('id')->get()->map->getAttributes());

        $result = app(DailyPhotoManualRetryService::class)->execute();
        $this->assertSame(0, $result['requeued']);
        $this->assertEquals($snapshots, OcrJob::query()->whereKey([$human->id, $reviewed->id, $corrected->id])->orderBy('id')->get()->map->getAttributes());
    }

    public function test_canonical_evidence_is_a_fail_safe_guard(): void
    {
        $job = $this->manualJob('canonical');
        $machine = $this->machine('T-XX0717');
        $case = DailyPhotoCase::query()->create([
            'scope_key' => 'manual-retry-canonical',
            'machine_id' => $machine->id,
            'work_date' => '2026-09-22',
            'status' => DailyPhotoCase::STATUS_READY,
            'source_version' => 'test',
        ]);
        $job->update(['daily_photo_case_id' => $case->id]);
        $before = $job->fresh()->getAttributes();

        $result = app(DailyPhotoManualRetryService::class)->execute();

        $this->assertSame(1, $result['already_resolved_skipped']);
        $this->assertSame(0, $result['requeued']);
        $this->assertSame($before, $job->fresh()->getAttributes());
    }

    public function test_missing_source_image_is_skipped(): void
    {
        $job = $this->manualJob('missing-source');
        Storage::disk('local')->delete($job->attachment->storage_path);

        $result = app(DailyPhotoManualRetryService::class)->execute();

        $this->assertSame(1, $result['missing_source_image']);
        $this->assertSame(0, $result['requeued']);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
    }

    public function test_exception_with_active_lease_is_reported_and_skipped(): void
    {
        $job = $this->manualJob('active-lease');
        $job->update(['claimed_by' => 'worker', 'claimed_at' => now(), 'lease_expires_at' => now()->addMinute()]);

        $result = app(DailyPhotoManualRetryService::class)->execute();

        $this->assertSame(1, $result['active_processing_skipped']);
        $this->assertSame(0, $result['requeued']);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
    }

    public function test_pending_processing_targeted_retry_ignored_and_weekly_jobs_are_not_touched(): void
    {
        $pending = $this->manualJob('pending');
        $pending->update(['status' => 'PENDING']);
        $processing = $this->manualJob('processing');
        $processing->update(['status' => 'PROCESSING', 'claimed_by' => 'worker', 'lease_expires_at' => now()->addMinute()]);
        $targeted = $this->manualJob('targeted');
        $targeted->update(['status' => 'RETRY', 'ocr_retry_reason' => 'time', 'ocr_retry_attempts' => 1]);
        $hourMeter = $this->manualJob('hour-meter');
        $hourMeter->update(['document_type' => 'IGNORED_HOUR_METER', 'status' => 'COMPLETED']);
        $nonDaily = $this->manualJob('non-daily');
        $nonDaily->update(['document_type' => 'IGNORED_NON_DAILY_PHOTO', 'status' => 'COMPLETED']);
        $weekly = $this->manualJob('weekly');
        $weekly->update(['document_type' => 'WEEKLY_JOURNAL', 'status' => 'PAUSED']);
        $ids = [$pending->id, $processing->id, $targeted->id, $hourMeter->id, $nonDaily->id, $weekly->id];
        $before = OcrJob::query()->whereKey($ids)->orderBy('id')->get()->map->getAttributes();

        $result = app(DailyPhotoManualRetryService::class)->execute();

        $this->assertSame(0, $result['requeued']);
        $this->assertEquals($before, OcrJob::query()->whereKey($ids)->orderBy('id')->get()->map->getAttributes());
    }

    public function test_state_change_between_scan_and_mutation_fails_safe(): void
    {
        $job = $this->manualJob('race');

        $result = app(DailyPhotoManualRetryService::class)->execute(20, function () use ($job): void {
            OcrJob::query()->whereKey($job->id)->update(['review_status' => 'APPROVED', 'reviewed_at' => now()]);
        });

        $this->assertSame(0, $result['requeued']);
        $this->assertSame(1, $result['eligibility_changed']);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
        $this->assertSame('APPROVED', $job->fresh()->review_status);
    }

    public function test_normal_new_daily_photo_claim_path_does_not_gain_manual_retry_metadata(): void
    {
        $job = $this->manualJob('normal-pipeline');
        $job->update([
            'status' => 'PENDING',
            'attempts' => 0,
            'ocr_retry_attempts' => 0,
            'daily_metadata' => null,
        ]);

        $claimed = app(OcrJobService::class)->claim('normal-worker', ['DAILY_TIMEMARK']);

        $this->assertSame($job->id, $claimed?->id);
        $this->assertSame(1, $claimed?->attempts);
        $this->assertNull($claimed?->daily_metadata);
    }

    public function test_one_thousand_manual_records_are_scanned_in_chunks_with_bounded_queries(): void
    {
        $now = now();
        $messages = $attachments = $jobs = [];
        foreach (range(1, 1000) as $index) {
            $messages[] = [
                'group_id' => 'manual-retry-volume',
                'message_id' => "manual-retry-{$index}",
                'sender_id' => 'manual-retry-sender',
                'sender_name' => 'Manual Retry',
                'sent_at' => $now,
                'received_at' => $now,
                'status' => 'STORED',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($messages, 200) as $chunk) {
            DB::table('zalo_messages')->insert($chunk);
        }
        foreach (DB::table('zalo_messages')->where('group_id', 'manual-retry-volume')->orderBy('id')->pluck('id') as $index => $messageId) {
            $attachments[] = [
                'zalo_message_id' => $messageId,
                'attachment_index' => 0,
                'original_name' => 'missing.jpg',
                'storage_disk' => 'local',
                'storage_path' => "manual-retry-volume/{$index}.jpg",
                'sha256' => hash('sha256', "manual-retry-{$index}"),
                'mime_type' => 'image/jpeg',
                'byte_size' => 1,
                'status' => 'STORED',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($attachments, 250) as $chunk) {
            DB::table('zalo_attachments')->insert($chunk);
        }
        foreach (DB::table('zalo_attachments')->where('storage_path', 'like', 'manual-retry-volume/%')->orderBy('id')->pluck('id') as $index => $attachmentId) {
            $jobs[] = [
                'zalo_attachment_id' => $attachmentId,
                'document_type' => 'DAILY_TIMEMARK',
                'status' => 'EXCEPTION',
                'review_status' => $index % 5 === 0 ? 'APPROVED' : 'PENDING',
                'attempts' => 3,
                'ocr_retry_attempts' => 1,
                'exceptions' => json_encode(['CAPTURE_TIME_AMBIGUOUS']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($jobs, 250) as $chunk) {
            DB::table('ocr_jobs')->insert($chunk);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $startedAt = microtime(true);
        $result = app(DailyPhotoManualRetryService::class)->preview(0);

        $this->assertSame(1000, $result['total_manual_considered']);
        $this->assertSame(200, $result['protected_skipped']);
        $this->assertSame(800, $result['missing_source_image']);
        $this->assertLessThan(30, microtime(true) - $startedAt);
        $this->assertLessThanOrEqual(55, count($queries), implode(PHP_EOL, $queries));
    }

    private function manualJob(string $sender): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'manual-retry',
            'message_id' => (string) Str::uuid(),
            'sender_id' => $sender,
            'sender_name' => $sender,
            'sent_at' => '2026-09-22 07:00:00',
            'received_at' => '2026-09-22 07:01:00',
            'status' => 'STORED',
        ]);
        $path = 'manual-retry/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'image-bytes');
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'daily.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', $path),
            'mime_type' => 'image/jpeg',
            'byte_size' => 11,
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'document_type' => 'DAILY_TIMEMARK',
            'status' => 'EXCEPTION',
            'review_status' => 'PENDING',
            'attempts' => 3,
            'ocr_retry_attempts' => 1,
            'raw_text' => 'legacy raw OCR',
            'exceptions' => ['CAPTURE_TIME_AMBIGUOUS'],
        ]);
    }

    private function machine(string $assetCode): Machine
    {
        return Machine::query()->create([
            'asset_code' => $assetCode,
            'chassis_no' => 'CHASSIS-'.Str::uuid(),
            'company' => 'VINCONS',
            'status' => 'ACTIVE',
        ]);
    }
}
