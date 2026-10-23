<?php

namespace Tests\Feature;

use App\Models\DailyPhotoAiRescueAttempt;
use App\Models\DailyPhotoAiRescueRun;
use App\Models\OcrJob;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyPhotoAiRescueBatchService;
use App\Services\DailyPhotoAiRescueRunService;
use App\Services\DailyPhotoAiRescueService;
use App\Services\DailyPhotoBacklogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DailyPhotoAiRescueRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_photos.enabled' => true]);
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    private function fixture(int $count = 2062): array
    {
        $m = ZaloMessage::create(['group_id' => 'ai-run', 'message_id' => uniqid('ai', true), 'sender_id' => 'synthetic', 'status' => 'STORED', 'received_at' => '2026-09-01 07:00:00', 'sent_at' => '2026-09-01 07:00:00']);
        Storage::disk('local')->put('ai-run.jpg', 'synthetic source');
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $a = ZaloAttachment::create(['zalo_message_id' => $m->id, 'attachment_index' => $i, 'original_name' => 'source.jpg', 'storage_disk' => 'local', 'storage_path' => $i === 2 ? 'missing.jpg' : 'ai-run.jpg', 'sha256' => hash('sha256', 'ai'.$i), 'mime_type' => 'image/jpeg', 'byte_size' => 16, 'status' => 'STORED']);
            $ids[] = DB::table('ocr_jobs')->insertGetId(['zalo_attachment_id' => $a->id, 'document_type' => 'DAILY_TIMEMARK', 'status' => 'EXCEPTION', 'review_status' => $i < 2 ? 'APPROVED' : 'PENDING', 'reviewed_at' => $i < 2 ? now() : null, 'attempts' => 3, 'ocr_retry_attempts' => 1, 'exceptions' => json_encode(['MISSING_DATE', 'MISSING_TIME']), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $ids;
    }

    private function finishEnqueue(DailyPhotoAiRescueRun $run): DailyPhotoAiRescueRun
    {
        $worker = app(DailyPhotoAiRescueRunService::class);
        for ($i = 0; $i < $run->selected_count + 5 && ! $run->fresh()->enqueue_done && $run->fresh()->status !== 'FAILED'; $i++) {
            $worker->processNext($run->id);
        }

        return $run->fresh();
    }

    public function test_2062_snapshot_is_readonly_http_is_light_and_worker_enqueues_unique_eligible_set(): void
    {
        $ids = $this->fixture();
        $actor = auth()->user();
        $this->mock(DailyPhotoBacklogService::class)->shouldNotReceive('report', 'analyse');
        $batch = app(DailyPhotoAiRescueBatchService::class);
        $this->postJson(route('ocr-reviews.ai-rescue.selection-count'), ['reason_groups' => ['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING']])->assertOk()->assertJsonPath('unique_photos', 2062);
        $this->post(route('ocr-reviews.ai-rescue.preview'), ['reason_groups' => ['CAPTURE_DATE_MISSING']])->assertOk()->assertSee('chưa gọi AI');
        $preview = $batch->preview(['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING']);
        $this->assertSame(4124, $preview['matched_records']);
        $this->assertTrue($preview['eligibility_deferred']);
        $this->assertDatabaseCount('daily_photo_ai_rescue_runs', 0);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 0);
        $time = microtime(true);
        $this->post(route('ocr-reviews.ai-rescue.execute'), ['preview_token' => $preview['preview_token']])->assertRedirect();
        $this->assertLessThan(2.0, microtime(true) - $time);
        $run = DailyPhotoAiRescueRun::sole();
        $this->post(route('ocr-reviews.ai-rescue.execute'), ['preview_token' => $preview['preview_token']])->assertRedirect(route('ocr-reviews.ai-rescue.progress', $run));
        $this->assertDatabaseCount('daily_photo_ai_rescue_runs', 1);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 0);
        $this->getJson(route('ocr-reviews.ai-rescue.progress', $run))->assertOk()->assertJsonPath('selected', 2062)->assertJsonPath('enqueued', 0);
        $this->get(route('ocr-reviews.ai-rescue.progress', $run))->assertOk()->assertSee('không đồng nghĩa OCR hoàn tất');
        OcrJob::whereKey($ids[3])->update(['review_status' => 'CORRECTED', 'reviewed_at' => now()]);
        $late = $this->fixture(1); // Eligible late arrival must still stay outside the snapshot.
        OcrJob::whereKey($late)->update(['review_status' => 'PENDING', 'reviewed_at' => null]);
        app()->forgetInstance(DailyPhotoBacklogService::class);
        app()->forgetInstance(DailyPhotoAiRescueBatchService::class);
        $done = $this->finishEnqueue($run);
        $this->assertTrue($done->enqueue_done);
        $this->assertSame(2058, $done->enqueued);
        $this->assertSame(4, $done->skipped);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 2058);
        $this->assertSame(0, DailyPhotoAiRescueAttempt::whereIn('ocr_job_id', $late)->count());
        $progress = app(DailyPhotoAiRescueRunService::class)->progress($done);
        $this->assertSame('OCR_RUNNING', $progress['status']);
        $this->assertSame(2058, $progress['ocr']['queued']);
        $this->assertSame(0, $progress['ocr']['succeeded']);
        // Replay after terminal attempts also must not create fresh paid work.
        $attempt = app(DailyPhotoAiRescueService::class)->claim('mock-worker');
        app(DailyPhotoAiRescueService::class)->fail($attempt, ['worker_id' => 'mock-worker', 'attempt' => $attempt->attempts, 'error' => 'mock terminal', 'retryable' => false]);
        $same = $batch->execute($preview['preview_token'], $actor);
        $this->assertSame($run->id, $same->id);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 2058);
        $this->assertSame(1, app(DailyPhotoAiRescueRunService::class)->progress($done)['ocr']['failed']);
    }

    public function test_owner_token_expiry_and_progress_scope_are_enforced(): void
    {
        $this->fixture(4);
        $batch = app(DailyPhotoAiRescueBatchService::class);
        $preview = $batch->preview(['CAPTURE_DATE_MISSING']);
        $actor = auth()->user();
        $run = $batch->execute($preview['preview_token'], $actor);
        $this->actingAs(User::factory()->create());
        $this->post(route('ocr-reviews.ai-rescue.execute'), ['preview_token' => $preview['preview_token']])->assertForbidden();
        $this->getJson(route('ocr-reviews.ai-rescue.progress', $run))->assertForbidden();
        $this->post(route('ocr-reviews.ai-rescue.retry', $run))->assertForbidden();
        auth()->logout();
        $this->getJson(route('ocr-reviews.ai-rescue.progress', $run))->assertUnauthorized();
        $this->actingAs($actor);
        $this->travel(31)->minutes();
        $this->post(route('ocr-reviews.ai-rescue.execute'), ['preview_token' => $preview['preview_token']])->assertSessionHasErrors('preview_token');
        $this->travelBack();
    }

    public function test_progress_tracks_processing_success_review_failure_and_bounded_abandoned_leases(): void
    {
        $this->fixture(6);
        $batch = app(DailyPhotoAiRescueBatchService::class);
        $run = $batch->execute($batch->preview(['CAPTURE_DATE_MISSING'])['preview_token'], auth()->user());
        $worker = app(DailyPhotoAiRescueRunService::class);
        $run->update(['lease_token' => 'held', 'lease_until' => now()->addMinutes(2), 'failures' => 1]);
        $this->assertFalse($worker->processNext($run->id));
        $this->assertSame(0, $run->fresh()->cursor);
        $run->update(['lease_until' => now()->subSecond(), 'failures' => 3]);
        $this->assertFalse($worker->processNext($run->id));
        $this->assertSame('FAILED', $run->fresh()->status);
        $worker->retry($run->fresh());
        $run = $this->finishEnqueue($run);
        $this->assertSame(3, $worker->progress($run)['ocr']['queued']);
        $rescue = app(DailyPhotoAiRescueService::class);
        $first = $rescue->claim('mock');
        $this->assertSame(1, $worker->progress($run)['ocr']['processing']);
        $rescue->fail($first, ['worker_id' => 'mock', 'attempt' => $first->attempts, 'retryable' => false, 'error' => 'mock failure']);
        foreach (['UNKNOWN', 'NON_DAILY_OTHER'] as $classification) {
            $attempt = $rescue->claim('mock');
            $payload = ['worker_id' => 'mock', 'attempt' => $attempt->attempts, 'provider' => 'local-mock', 'model' => 'fixture',
                'prompt_version' => $attempt->prompt_version, 'schema_version' => $attempt->schema_version, 'raw_response' => '{}',
                'result' => ['classification' => $classification, 'machine' => null, 'capture_date' => null, 'capture_time' => null, 'ambiguities' => []]];
            $rescue->complete($attempt, $payload);
            $rescue->complete($attempt, $payload);
        }
        $stats = $worker->progress($run);
        $this->assertSame(['queued' => 0, 'processing' => 0, 'succeeded' => 1, 'review' => 1, 'failed' => 1], $stats['ocr']);
        $this->assertSame('COMPLETED_WITH_REVIEW', $stats['status']);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 3);
    }

    public function test_late_domain_ineligibility_is_a_skip_without_partial_attempt_or_stuck_run(): void
    {
        $ids = $this->fixture(4);
        $batch = app(DailyPhotoAiRescueBatchService::class);
        $run = $batch->execute($batch->preview(['CAPTURE_DATE_MISSING'])['preview_token'], auth()->user());
        $real = app(DailyPhotoAiRescueService::class);
        $this->mock(DailyPhotoAiRescueService::class)->shouldReceive('eligibilityReason')->andReturnUsing(fn (...$args) => $real->eligibilityReason(...$args));
        app(DailyPhotoAiRescueService::class)->shouldReceive('request')->once()->andThrow(\Illuminate\Validation\ValidationException::withMessages(['ocr_job' => 'SOURCE_MISSING']));
        app()->forgetInstance(DailyPhotoAiRescueBatchService::class);
        app()->forgetInstance(DailyPhotoAiRescueRunService::class);
        $done = $this->finishEnqueue($run);
        $this->assertTrue($done->enqueue_done);
        $this->assertSame(4, $done->skipped);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 0);
    }

    public function test_failure_rolls_back_attempt_item_cursor_and_retry_does_not_duplicate_work(): void
    {
        $ids = $this->fixture(5);
        $batch = app(DailyPhotoAiRescueBatchService::class);
        $run = $batch->execute($batch->preview(['CAPTURE_DATE_MISSING'])['preview_token'], auth()->user());
        $real = app(DailyPhotoAiRescueService::class);
        $this->mock(DailyPhotoAiRescueService::class)->shouldReceive('eligibilityReason')->andReturnUsing(fn (...$args) => $real->eligibilityReason(...$args));
        app(DailyPhotoAiRescueService::class)->shouldReceive('request')->times(3)->andReturnUsing(function ($job) use ($real) {
            $real->request($job);
            throw new \RuntimeException('after real attempt insert');
        });
        app()->forgetInstance(DailyPhotoAiRescueBatchService::class);
        app()->forgetInstance(DailyPhotoAiRescueRunService::class);
        $failed = $this->finishEnqueue($run);
        $this->assertSame('FAILED', $failed->status);
        $this->assertSame(3, $failed->cursor);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 0);
        $this->assertDatabaseCount('daily_photo_ai_rescue_run_items', 3);
        app()->forgetInstance(DailyPhotoAiRescueService::class);
        app()->forgetInstance(DailyPhotoAiRescueBatchService::class);
        app()->forgetInstance(DailyPhotoAiRescueRunService::class);
        app(DailyPhotoAiRescueRunService::class)->retry($failed);
        $done = $this->finishEnqueue($run);
        $this->assertTrue($done->enqueue_done);
        $this->assertSame(2,$done->enqueued);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts',2);
    }
}
