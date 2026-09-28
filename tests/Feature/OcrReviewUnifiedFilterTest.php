<?php

namespace Tests\Feature;

use App\Models\DailyPhotoAiRescueAttempt;
use App\Models\DailyPhotoCase;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyPhotoAiRescueBatchService;
use App\Services\OcrReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OcrReviewUnifiedFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_photos.enabled' => true]);
        $this->user = User::factory()->create();
        $this->machine = Machine::query()->create([
            'asset_code' => 'VT-FILTER-01',
            'company' => 'VINCONS',
            'chassis_no' => 'FILTER-CHASSIS-01',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_default_manual_canonical_protected_and_reviewed_populations_are_queryable(): void
    {
        $manual = $this->job('manual', ['CAPTURE_TIME_MISSING']);
        $canonical = $this->job('canonical', [], status: 'COMPLETED', machine: $this->machine, date: '2026-09-20', time: '07:00:00');
        $case = DailyPhotoCase::query()->create([
            'scope_key' => 'filter-case', 'machine_id' => $this->machine->id,
            'work_date' => '2026-09-20', 'status' => 'COLLECTING', 'source_version' => 'test',
        ]);
        $canonical->update(['daily_photo_case_id' => $case->id]);
        $protected = $this->job('protected', ['CAPTURE_DATE_MISSING']);
        $protected->update(['reviewed_at' => now(), 'review_status' => 'CORRECTED']);

        $this->assertIds([], [$manual, $canonical, $protected]);
        $this->assertIds(['workflow' => 'manual'], [$manual, $protected]);
        $this->assertIds(['workflow' => 'canonical'], [$canonical]);
        $this->assertIds(['workflow' => 'protected'], [$protected]);
        $this->assertIds(['workflow' => 'reviewed'], [$protected]);
    }

    public function test_ai_history_and_every_latest_ai_status_filter_use_latest_attempt_only(): void
    {
        $never = $this->job('never', ['CAPTURE_TIME_MISSING']);
        $queued = $this->job('queued', ['CAPTURE_TIME_MISSING']);
        $processing = $this->job('processing', ['CAPTURE_TIME_MISSING']);
        $human = $this->job('human', ['CAPTURE_TIME_MISSING']);
        $failed = $this->job('failed', ['CAPTURE_TIME_MISSING']);
        $skipped = $this->job('skipped', ['CAPTURE_TIME_MISSING']);
        $resolved = $this->job('resolved', [], status: 'COMPLETED', machine: $this->machine, date: '2026-09-20', time: '07:00:00');
        $nonDaily = $this->job('non-daily', [], status: 'COMPLETED', documentType: 'IGNORED_NON_DAILY_PHOTO');

        $this->attempt($queued, 'PENDING');
        $this->attempt($processing, 'PROCESSING');
        $this->attempt($human, 'COMPLETED', 'HUMAN_REQUIRED');
        $this->attempt($failed, 'FAILED', 'FAILED');
        $this->attempt($skipped, 'SKIPPED', 'SKIPPED_PROTECTED');
        $this->attempt($resolved, 'FAILED', 'FAILED');
        $this->attempt($resolved, 'COMPLETED', 'RESOLVED');
        $this->attempt($nonDaily, 'COMPLETED', 'NON_DAILY');

        $this->assertIds(['ocr_source' => 'never_ai'], [$never]);
        $this->assertIds(['ocr_source' => 'ai_attempted'], [$queued, $processing, $human, $failed, $skipped, $resolved, $nonDaily]);
        $this->assertIds(['ai_status' => 'never'], [$never]);
        $this->assertIds(['ai_status' => 'queued'], [$queued]);
        $this->assertIds(['ai_status' => 'processing'], [$processing]);
        $this->assertIds(['ai_status' => 'active'], [$queued, $processing]);
        $this->assertIds(['ai_status' => 'resolved'], [$resolved]);
        $this->assertIds(['ai_status' => 'resolved', 'machine_id' => $this->machine->id], [$resolved]);
        $this->assertIds(['ai_status' => 'human_required'], [$human]);
        $this->assertIds(['ai_status' => 'non_daily'], [$nonDaily]);
        $this->assertIds(['ai_status' => 'failed'], [$failed]);
        $this->assertIds(['ai_status' => 'skipped'], [$skipped]);
        $this->assertIds(['ai_status' => 'failed_or_skipped'], [$failed, $skipped]);
    }

    public function test_reason_date_machine_sender_and_search_filters_compose_in_sql(): void
    {
        $match = $this->job('sender-match', ['MACHINE_AMBIGUOUS', 'CAPTURE_TIME_MISSING'], '2026-09-20 08:00:00', machine: $this->machine);
        $other = $this->job('sender-other', ['CAPTURE_TIME_MISSING'], '2026-08-01 08:00:00');
        $invalid = $this->job('rapid-invalid', ['MACHINE_OCR_INVALID'], '2026-09-20 08:00:00');
        $attempt = $this->attempt($match, 'COMPLETED', 'HUMAN_REQUIRED');

        $filters = [
            'workflow' => 'manual',
            'ocr_source' => 'ai_attempted',
            'ai_status' => 'human_required',
            'reason' => 'MACHINE_AMBIGUOUS',
            'machine_id' => $this->machine->id,
            'sender' => 'sender-match',
            'date_from' => '2026-09-20',
            'date_to' => '2026-09-20',
        ];
        $this->assertIds($filters, [$match]);
        $this->assertIds([
            'workflow' => 'manual', 'ocr_source' => 'never_ai', 'reason' => 'CAPTURE_TIME_MISSING',
            'date_from' => '2026-08-01', 'date_to' => '2026-08-01',
        ], [$other]);
        $this->assertIds([
            'workflow' => 'manual', 'ocr_source' => 'rapidocr', 'reason' => 'MACHINE_OCR_INVALID',
        ], [$invalid]);
        $this->assertIds(['q' => (string) $match->id], [$match]);
        $this->assertIds(['q' => 'message-sender-match'], [$match]);
        $this->assertSame('HUMAN_REQUIRED', $attempt->final_resolution);
    }

    public function test_metric_counts_match_their_clickable_filter_population(): void
    {
        $never = $this->job('metric-never', ['CAPTURE_TIME_MISSING']);
        $active = $this->job('metric-active', ['CAPTURE_TIME_MISSING']);
        $human = $this->job('metric-human', ['MACHINE_AMBIGUOUS']);
        $resolved = $this->job('metric-resolved', [], status: 'COMPLETED', machine: $this->machine, date: '2026-09-20', time: '07:00:00');
        $nonDaily = $this->job('metric-non-daily', [], status: 'COMPLETED', documentType: 'IGNORED_NON_DAILY_PHOTO');
        $failed = $this->job('metric-failed', ['CAPTURE_DATE_MISSING']);
        $skipped = $this->job('metric-skipped', ['CAPTURE_DATE_MISSING']);
        $this->attempt($active, 'PROCESSING');
        $this->attempt($human, 'COMPLETED', 'HUMAN_REQUIRED');
        $this->attempt($resolved, 'COMPLETED', 'RESOLVED');
        $this->attempt($nonDaily, 'COMPLETED', 'NON_DAILY');
        $this->attempt($failed, 'FAILED', 'FAILED');
        $this->attempt($skipped, 'SKIPPED', 'SKIPPED_STALE');

        $dashboard = app(DailyPhotoAiRescueBatchService::class)->dashboard();
        $this->assertSame($this->filteredCount(['workflow' => 'manual', 'ai_status' => 'never']), $dashboard['metrics']['never_attempted']);
        $this->assertSame($this->filteredCount(['ai_status' => 'active']), $dashboard['metrics']['queued_processing']);
        $this->assertSame($this->filteredCount(['ai_status' => 'resolved']), $dashboard['metrics']['resolved']);
        $this->assertSame($this->filteredCount(['ai_status' => 'human_required']), $dashboard['metrics']['human_required']);
        $this->assertSame($this->filteredCount(['ai_status' => 'non_daily']), $dashboard['metrics']['non_daily']);
        $this->assertSame($this->filteredCount(['ai_status' => 'failed']), $dashboard['metrics']['failed']);
        $this->assertSame($this->filteredCount(['ai_status' => 'skipped']), $dashboard['metrics']['skipped']);

        $ambiguous = collect($dashboard['reason_groups'])->firstWhere('code', 'MACHINE_AMBIGUOUS');
        $this->assertSame($this->filteredCount(['workflow' => 'manual', 'reason' => 'MACHINE_AMBIGUOUS']), $ambiguous['count']);
        $this->assertTrue($never->exists);
    }

    public function test_url_state_pagination_reset_empty_state_and_invalid_filter_are_safe(): void
    {
        foreach (range(1, 35) as $index) {
            $this->job('page-'.$index, ['CAPTURE_TIME_MISSING']);
        }

        $response = $this->actingAs($this->user)->get(route('ocr-reviews.index', [
            'workflow' => 'manual', 'reason' => 'CAPTURE_TIME_MISSING', 'page' => 2,
        ]));
        $response->assertOk()
            ->assertSee('Đang áp dụng bộ lọc')
            ->assertSee('reason=CAPTURE_TIME_MISSING', false)
            ->assertSee('Xóa bộ lọc');

        $this->actingAs($this->user)->get(route('ocr-reviews.index', ['sender' => 'does-not-exist']))
            ->assertOk()->assertSee('Không có ảnh phù hợp với bộ lọc hiện tại.');
        $this->actingAs($this->user)->get(route('ocr-reviews.index', ['ai_status' => 'invented']))
            ->assertSessionHasErrors('ai_status');
    }

    public function test_bulk_reason_selection_count_is_server_side_unique_and_read_only(): void
    {
        $both = $this->job('selection-both', ['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING']);
        $this->job('selection-time', ['CAPTURE_TIME_MISSING']);

        $this->actingAs($this->user)->postJson(route('ocr-reviews.ai-rescue.selection-count'), [
            'reason_groups' => ['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING'],
        ])->assertOk()->assertJson(['unique_photos' => 2]);

        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 0);
        $this->assertSame('EXCEPTION', $both->fresh()->status);
    }

    private function assertIds(array $filters, array $expected): void
    {
        $actual = app(OcrReviewService::class)->filteredQuery($filters)->pluck('ocr_jobs.id')->sort()->values()->all();
        $ids = collect($expected)->map(fn (OcrJob $job): int => $job->id)->sort()->values()->all();
        $this->assertSame($ids, $actual);
    }

    private function filteredCount(array $filters): int
    {
        return app(OcrReviewService::class)->filteredQuery($filters)->count();
    }

    private function job(
        string $sender,
        array $exceptions,
        string $sentAt = '2026-09-20 08:00:00',
        string $status = 'EXCEPTION',
        ?Machine $machine = null,
        ?string $date = null,
        ?string $time = null,
        string $documentType = 'DAILY_TIMEMARK',
    ): OcrJob {
        $message = ZaloMessage::query()->create([
            'group_id' => 'review-filter', 'message_id' => 'message-'.$sender,
            'sender_id' => $sender, 'sender_name' => 'Tên '.$sender,
            'sent_at' => $sentAt, 'received_at' => $sentAt, 'status' => 'STORED',
        ]);
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id, 'attachment_index' => 0,
            'original_name' => 'filter.jpg', 'storage_disk' => 'local',
            'storage_path' => 'filter/'.Str::uuid().'.jpg', 'sha256' => hash('sha256', $sender),
            'mime_type' => 'image/jpeg', 'byte_size' => 100, 'status' => 'STORED',
        ]);

        return OcrJob::withoutEvents(fn () => OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'machine_id' => $machine?->id,
            'asset_code' => $machine?->asset_code,
            'observed_asset_code' => $machine?->asset_code,
            'document_type' => $documentType,
            'status' => $status,
            'review_status' => $status === 'EXCEPTION' ? 'PENDING' : 'AUTO_APPROVED',
            'attempts' => 1,
            'extracted_date' => $date,
            'extracted_time' => $time,
            'exceptions' => $exceptions ?: null,
        ]));
    }

    private function attempt(OcrJob $job, string $status, ?string $resolution = null): DailyPhotoAiRescueAttempt
    {
        return DailyPhotoAiRescueAttempt::query()->create([
            'ocr_job_id' => $job->id,
            'zalo_attachment_id' => $job->zalo_attachment_id,
            'source_sha256' => $job->attachment->sha256,
            'status' => $status,
            'active_key' => in_array($status, ['PENDING', 'RETRY', 'PROCESSING'], true) ? 'ACTIVE' : null,
            'attempts' => 1,
            'prompt_version' => 'daily_photo_rescue_v1',
            'schema_version' => 'daily_photo_rescue_v1',
            'classification' => $resolution === 'NON_DAILY' ? 'NON_DAILY_OTHER' : 'DAILY_PHOTO',
            'final_resolution' => $resolution,
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
            'requested_at' => now(),
            'processed_at' => $resolution ? now() : null,
        ]);
    }
}
