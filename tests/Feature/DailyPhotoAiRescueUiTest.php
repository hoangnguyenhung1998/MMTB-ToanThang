<?php

namespace Tests\Feature;

use App\Models\DailyPhotoAiRescueAttempt;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyPhotoAiRescueBatchService;
use App\Services\DailyPhotoAiRescueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyPhotoAiRescueUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 28, 16, 0, 0, 'UTC'));
        config([
            'daily_photos.enabled' => true,
            'daily_photos.capture_timezone' => 'Asia/Ho_Chi_Minh',
            'daily_photos.ai_rescue.prompt_version' => 'daily_photo_rescue_v1',
            'daily_photos.ai_rescue.schema_version' => 'daily_photo_rescue_v1',
            'daily_photos.ai_rescue.lease_seconds' => 600,
            'daily_photos.ai_rescue.max_attempts' => 3,
            'daily_photos.ai_rescue.preview_ttl_minutes' => 30,
        ]);
        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manual_dashboard_renders_reason_groups_bulk_action_metrics_and_no_secret(): void
    {
        $this->manualJob('dashboard', ['MISSING_TIME'], date: '2026-09-28');

        $this->actingAs($this->user)->get(route('ocr-reviews.index'))
            ->assertOk()
            ->assertSee('AI Rescue cho ảnh Manual')
            ->assertSee('Thiếu giờ chụp')
            ->assertSee('OCR lại bằng AI (0 ảnh)')
            ->assertSee('Chưa chạy AI')
            ->assertDontSee('JOURNAL_VISION_API_KEY')
            ->assertDontSee('raw_response');
    }

    public function test_single_action_enqueues_once_and_rejects_protected_resolved_and_missing_source(): void
    {
        $eligible = $this->manualJob('single', ['MISSING_TIME'], date: '2026-09-28');
        $this->actingAs($this->user)->post(route('ocr-reviews.ai-rescue.single', $eligible))
            ->assertRedirect()->assertSessionHas('success');
        $this->actingAs($this->user)->post(route('ocr-reviews.ai-rescue.single', $eligible))
            ->assertSessionHasErrors('ai_rescue');
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 1);

        $protected = $this->manualJob('protected', ['MISSING_TIME'], date: '2026-09-28');
        $protected->update(['reviewed_at' => now(), 'review_status' => 'CORRECTED']);
        $this->actingAs($this->user)->post(route('ocr-reviews.ai-rescue.single', $protected))
            ->assertSessionHasErrors('ai_rescue');

        $resolved = $this->manualJob('resolved', ['MISSING_TIME'], date: '2026-09-28');
        $resolved->update(['status' => 'COMPLETED']);
        $this->actingAs($this->user)->post(route('ocr-reviews.ai-rescue.single', $resolved))
            ->assertSessionHasErrors('ai_rescue');

        $missing = $this->manualJob('missing', ['MISSING_TIME'], date: '2026-09-28');
        Storage::disk('local')->delete($missing->attachment->storage_path);
        $this->actingAs($this->user)->post(route('ocr-reviews.ai-rescue.single', $missing))
            ->assertSessionHasErrors('ai_rescue');

        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 1);
    }

    public function test_preview_deduplicates_overlapping_groups_counts_skips_and_does_not_mutate(): void
    {
        $both = $this->manualJob('both', ['MISSING_DATE', 'MISSING_TIME']);
        $protected = $this->manualJob('preview-protected', ['MISSING_TIME'], date: '2026-09-28');
        $protected->update(['reviewed_at' => now()]);
        $missing = $this->manualJob('preview-missing', ['MISSING_DATE']);
        Storage::disk('local')->delete($missing->attachment->storage_path);

        $preview = app(DailyPhotoAiRescueBatchService::class)->preview([
            'CAPTURE_DATE_MISSING',
            'CAPTURE_TIME_MISSING',
        ]);

        $this->assertSame(5, $preview['matched_records']);
        $this->assertSame(3, $preview['unique_photos']);
        $this->assertSame(1, $preview['will_queue']);
        $this->assertSame(1, $preview['counts']['protected']);
        $this->assertSame(1, $preview['counts']['missing_source']);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 0);
        $this->assertSame('EXCEPTION', $both->fresh()->status);

        $this->actingAs($this->user)->post(route('ocr-reviews.ai-rescue.preview'), [
            'reason_groups' => ['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING'],
        ])->assertOk()->assertSee('Preview AI Rescue')->assertSee('Sẽ OCR bằng AI');
    }

    public function test_bulk_confirm_rechecks_state_and_overlapping_requests_are_idempotent(): void
    {
        $changed = $this->manualJob('changed', ['MISSING_TIME'], date: '2026-09-28');
        $preview = app(DailyPhotoAiRescueBatchService::class)->preview(['CAPTURE_TIME_MISSING']);
        $changed->update(['reviewed_at' => now(), 'review_status' => 'CORRECTED']);
        $changedResult = app(DailyPhotoAiRescueBatchService::class)->execute($preview['preview_token'], $this->user);
        $this->assertSame(0, $changedResult['accepted']);
        $this->assertSame(1, $changedResult['counts']['protected']);

        $job = $this->manualJob('overlap', ['MISSING_DATE']);
        $overlap = app(DailyPhotoAiRescueBatchService::class)->preview(['CAPTURE_DATE_MISSING']);
        $first = app(DailyPhotoAiRescueBatchService::class)->execute($overlap['preview_token'], $this->user);
        $second = app(DailyPhotoAiRescueBatchService::class)->execute($overlap['preview_token'], $this->user);
        $this->assertSame(1, $first['accepted']);
        $this->assertSame(0, $second['accepted']);
        $this->assertSame(1, $second['counts']['ai_processing']);
        $this->assertSame(1, DailyPhotoAiRescueAttempt::query()->where('ocr_job_id', $job->id)->count());
    }

    public function test_single_and_bulk_overlap_do_not_duplicate_attempt(): void
    {
        $job = $this->manualJob('single-bulk', ['MISSING_TIME'], date: '2026-09-28');
        $preview = app(DailyPhotoAiRescueBatchService::class)->preview(['CAPTURE_TIME_MISSING']);

        $this->actingAs($this->user)->post(route('ocr-reviews.ai-rescue.single', $job))->assertSessionHas('success');
        $result = app(DailyPhotoAiRescueBatchService::class)->execute($preview['preview_token'], $this->user);

        $this->assertSame(0, $result['accepted']);
        $this->assertSame(1, $result['counts']['ai_processing']);
        $this->assertSame(1, $job->aiRescueAttempts()->count());
    }

    public function test_previous_human_required_failed_and_resolved_attempts_are_not_automatically_burned_again(): void
    {
        $human = $this->manualJob('human-required', ['MISSING_TIME'], date: '2026-09-28');
        $humanAttempt = $this->claim($human, 'human-worker');
        app(DailyPhotoAiRescueService::class)->complete($humanAttempt, $this->completionPayload($humanAttempt, 'human-worker', [
            'classification' => 'UNKNOWN', 'machine' => null, 'capture_date' => null, 'capture_time' => null, 'ambiguities' => ['uncertain'],
        ]));

        $failed = $this->manualJob('failed-previous', ['MISSING_TIME'], date: '2026-09-28');
        $failedAttempt = $this->claim($failed, 'failed-worker');
        app(DailyPhotoAiRescueService::class)->fail($failedAttempt, [
            'worker_id' => 'failed-worker', 'attempt' => $failedAttempt->attempts, 'error' => 'provider failed', 'retryable' => false,
        ]);

        $preview = app(DailyPhotoAiRescueBatchService::class)->preview(['CAPTURE_TIME_MISSING']);
        $this->assertSame(0, $preview['will_queue']);
        $this->assertSame(1, $preview['counts']['ai_unresolved_previous']);
        $this->assertSame(1, $preview['counts']['ai_failed_previous']);

        $machine = $this->machine('T-AI0717');
        $resolved = $this->manualJob('resolved-after-preview', ['MISSING_TIME'], date: '2026-09-28');
        $resolvedPreview = app(DailyPhotoAiRescueBatchService::class)->preview(['CAPTURE_TIME_MISSING']);
        $resolvedAttempt = $this->claim($resolved, 'resolved-worker');
        app(DailyPhotoAiRescueService::class)->complete($resolvedAttempt, $this->completionPayload($resolvedAttempt, 'resolved-worker', [
            'classification' => 'DAILY_PHOTO', 'machine' => $machine->asset_code, 'capture_date' => '2026-09-28', 'capture_time' => '10:30', 'ambiguities' => [],
        ]));
        $result = app(DailyPhotoAiRescueBatchService::class)->execute($resolvedPreview['preview_token'], $this->user);
        $this->assertSame(0, $result['accepted']);
        $this->assertGreaterThanOrEqual(1, $result['counts']['ai_already_resolved']);
    }

    public function test_detail_renders_status_and_history_without_raw_provider_response_or_api_key(): void
    {
        $job = $this->manualJob('history', ['MISSING_TIME'], date: '2026-09-28');
        $attempt = $this->claim($job, 'history-worker');
        $payload = $this->completionPayload($attempt, 'history-worker', [
            'classification' => 'UNKNOWN', 'machine' => null, 'capture_date' => null, 'capture_time' => null, 'ambiguities' => ['blurred'],
        ]);
        $payload['raw_response'] = 'RAW_SECRET_PROVIDER_RESPONSE';
        app(DailyPhotoAiRescueService::class)->complete($attempt, $payload);

        $this->actingAs($this->user)->get(route('ocr-reviews.show', $job))
            ->assertOk()
            ->assertSee('Lịch sử AI Rescue')
            ->assertSee('Cần kiểm tra thủ công')
            ->assertSee('UNKNOWN')
            ->assertSee('vision-model')
            ->assertDontSee('RAW_SECRET_PROVIDER_RESPONSE')
            ->assertDontSee('JOURNAL_VISION_API_KEY');
    }

    private function claim(OcrJob $job, string $worker): DailyPhotoAiRescueAttempt
    {
        app(DailyPhotoAiRescueService::class)->request($job);

        return app(DailyPhotoAiRescueService::class)->claim($worker);
    }

    private function completionPayload(DailyPhotoAiRescueAttempt $attempt, string $worker, array $result): array
    {
        return [
            'worker_id' => $worker,
            'attempt' => $attempt->attempts,
            'provider' => '9router-openai-compatible',
            'model' => 'vision-model',
            'prompt_version' => 'daily_photo_rescue_v1',
            'schema_version' => 'daily_photo_rescue_v1',
            'result' => $result,
            'raw_response' => '{}',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    private function manualJob(string $sender, array $exceptions, ?string $date = null, ?string $time = null): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'ai-rescue-ui',
            'message_id' => (string) Str::uuid(),
            'sender_id' => $sender,
            'sender_name' => $sender,
            'sent_at' => '2026-09-01 07:00:00',
            'received_at' => '2026-09-01 07:01:00',
            'status' => 'STORED',
        ]);
        $bytes = "original-{$sender}";
        $path = 'ai-rescue-ui/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $bytes);
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'daily.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', $bytes),
            'mime_type' => 'image/jpeg',
            'byte_size' => strlen($bytes),
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'document_type' => 'DAILY_TIMEMARK',
            'status' => 'EXCEPTION',
            'review_status' => 'PENDING',
            'attempts' => 3,
            'ocr_retry_attempts' => 1,
            'extracted_date' => $date,
            'extracted_time' => $time,
            'exceptions' => $exceptions,
            'raw_text' => 'rapid history',
            'daily_metadata' => ['ocr_candidate_summary' => ['machine_candidates' => [], 'date_candidates' => [], 'time_candidates' => [], 'conflicts' => []]],
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
