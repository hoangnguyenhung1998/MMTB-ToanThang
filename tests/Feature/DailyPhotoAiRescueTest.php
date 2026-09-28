<?php

namespace Tests\Feature;

use App\Models\DailyPhotoCase;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyPhotoAiRescueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DailyPhotoAiRescueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 16:59 UTC is 23:59 on 2026-09-28 in Asia/Ho_Chi_Minh.
        Carbon::setTestNow(Carbon::create(2026, 9, 28, 16, 59, 0, 'UTC'));
        config([
            'daily_photos.enabled' => true,
            'daily_photos.capture_timezone' => 'Asia/Ho_Chi_Minh',
            'daily_photos.ai_rescue.prompt_version' => 'daily_photo_rescue_v1',
            'daily_photos.ai_rescue.schema_version' => 'daily_photo_rescue_v1',
            'daily_photos.ai_rescue.lease_seconds' => 600,
            'daily_photos.ai_rescue.max_attempts' => 3,
            'ocr.worker_token' => 'test-ocr-token',
        ]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_valid_daily_result_uses_original_image_and_materializes_canonical_evidence(): void
    {
        $machine = $this->machine('T-XX0717');
        $job = $this->manualJob('valid-daily');
        $service = app(DailyPhotoAiRescueService::class);
        $requested = $service->request($job);

        $claim = $this->withToken('test-ocr-token')
            ->postJson('/api/ocr/v1/daily-ai-rescue/jobs/claim', ['worker_id' => 'vision-worker'])
            ->assertOk()
            ->assertJsonPath('job.id', $requested->id)
            ->assertJsonPath('job.ocr_job_id', $job->id)
            ->assertJsonPath('job.prompt_version', 'daily_photo_rescue_v1')
            ->json('job');

        $this->assertArrayNotHasKey('prior_extraction', $claim);
        $this->assertArrayNotHasKey('candidate_metadata', $claim);
        $this->withToken('test-ocr-token')->get($claim['image_url'])
            ->assertOk()
            ->assertStreamedContent('original-image-valid-daily');

        $response = $this->withToken('test-ocr-token')->postJson(
            "/api/ocr/v1/daily-ai-rescue/jobs/{$requested->id}/complete",
            $this->completionPayload($claim['attempt'], [
                'classification' => 'DAILY_PHOTO',
                'machine' => 'T-XX0717',
                'capture_date' => '2026-09-28',
                'capture_time' => '10:31',
                'ambiguities' => [],
            ]),
        )->assertOk()->assertJsonPath('job.final_resolution', 'RESOLVED');

        $fresh = $job->fresh(['dailyPhotoCaseEvidence', 'dailyPhotoCase']);
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertSame('AUTO_APPROVED', $fresh->review_status);
        $this->assertSame($machine->id, $fresh->machine_id);
        $this->assertSame('2026-09-28', $fresh->extracted_date->format('Y-m-d'));
        $this->assertSame('10:31:00', $fresh->extracted_time);
        $this->assertSame('AI_VISION', $fresh->machine_resolution_method);
        $this->assertSame('AI_RESCUE', $fresh->ocr_final_source);
        $this->assertNotNull($fresh->daily_photo_case_id);
        $this->assertNotNull($fresh->dailyPhotoCaseEvidence);
        $this->assertSame('rapid legacy raw', $fresh->raw_text);
        $this->assertSame(['T-OLD9999'], data_get($fresh->daily_metadata, 'ocr_candidate_summary.machine_candidates'));
        $this->assertSame(11, $response->json('job.prompt_tokens'));
        $this->assertSame('9router-openai-compatible', $response->json('job.provider'));
    }

    public function test_missing_daily_fields_and_ambiguities_remain_human_required(): void
    {
        $this->machine('T-XX0717');
        $cases = [
            ['missing-machine', null, '2026-09-28', '10:31', [], 'MACHINE_MISSING'],
            ['missing-date', 'T-XX0717', null, '10:31', [], 'CAPTURE_DATE_MISSING'],
            ['missing-time', 'T-XX0717', '2026-09-28', null, [], 'CAPTURE_TIME_MISSING'],
            ['ambiguous', 'T-XX0717', '2026-09-28', '10:31', ['two visible dates'], 'AMBIGUOUS_RESULT'],
        ];

        foreach ($cases as [$sender, $machine, $date, $time, $ambiguities, $reason]) {
            $job = $this->manualJob($sender);
            $attempt = $this->requestAndClaim($job, "worker-{$sender}");
            $completed = app(DailyPhotoAiRescueService::class)->complete($attempt, $this->completionPayload(
                $attempt->attempts,
                [
                    'classification' => 'DAILY_PHOTO',
                    'machine' => $machine,
                    'capture_date' => $date,
                    'capture_time' => $time,
                    'ambiguities' => $ambiguities,
                ],
                "worker-{$sender}",
            ));

            $this->assertSame('HUMAN_REQUIRED', $completed->final_resolution);
            $this->assertContains($reason, $completed->validation_outcome['reasons']);
            $this->assertSame('EXCEPTION', $job->fresh()->status);
            $this->assertNull($job->fresh()->daily_photo_case_id);
        }
    }

    public function test_invalid_machine_is_not_fuzzy_matched_or_invented(): void
    {
        $this->machine('T-XX0717');
        $job = $this->manualJob('invalid-machine');
        $attempt = $this->requestAndClaim($job, 'invalid-machine-worker');

        $completed = app(DailyPhotoAiRescueService::class)->complete($attempt, $this->completionPayload(
            $attempt->attempts,
            [
                'classification' => 'DAILY_PHOTO',
                'machine' => 'T-XX071X',
                'capture_date' => '2026-09-28',
                'capture_time' => '10:31',
                'ambiguities' => [],
            ],
            'invalid-machine-worker',
        ));

        $this->assertSame('HUMAN_REQUIRED', $completed->final_resolution);
        $this->assertContains('MACHINE_INVALID', $completed->validation_outcome['reasons']);
        $this->assertNull($job->fresh()->machine_id);
    }

    public function test_hour_meter_is_excluded_but_unknown_stays_manual(): void
    {
        $hourMeter = $this->manualJob('hour-meter');
        $hourAttempt = $this->requestAndClaim($hourMeter, 'hour-worker');
        $completed = app(DailyPhotoAiRescueService::class)->complete($hourAttempt, $this->completionPayload(
            $hourAttempt->attempts,
            [
                'classification' => 'NON_DAILY_HOUR_METER',
                'machine' => null,
                'capture_date' => null,
                'capture_time' => null,
                'ambiguities' => [],
            ],
            'hour-worker',
        ));

        $this->assertSame('NON_DAILY', $completed->final_resolution);
        $this->assertSame('IGNORED_HOUR_METER', $hourMeter->fresh()->document_type);
        $this->assertSame('COMPLETED', $hourMeter->fresh()->status);

        $unknown = $this->manualJob('unknown');
        $unknownAttempt = $this->requestAndClaim($unknown, 'unknown-worker');
        $completed = app(DailyPhotoAiRescueService::class)->complete($unknownAttempt, $this->completionPayload(
            $unknownAttempt->attempts,
            [
                'classification' => 'UNKNOWN',
                'machine' => null,
                'capture_date' => null,
                'capture_time' => null,
                'ambiguities' => ['classification uncertain'],
            ],
            'unknown-worker',
        ));

        $this->assertSame('HUMAN_REQUIRED', $completed->final_resolution);
        $this->assertSame('DAILY_TIMEMARK', $unknown->fresh()->document_type);
        $this->assertSame('EXCEPTION', $unknown->fresh()->status);
    }

    public function test_explicit_non_daily_other_is_excluded_without_storing_daily_fields(): void
    {
        $job = $this->manualJob('non-daily-other');
        $attempt = $this->requestAndClaim($job, 'non-daily-worker');

        $completed = app(DailyPhotoAiRescueService::class)->complete($attempt, $this->completionPayload(
            $attempt->attempts,
            [
                'classification' => 'NON_DAILY_OTHER',
                'machine' => null,
                'capture_date' => null,
                'capture_time' => null,
                'ambiguities' => [],
            ],
            'non-daily-worker',
        ));

        $fresh = $job->fresh();
        $this->assertSame('NON_DAILY', $completed->final_resolution);
        $this->assertSame('IGNORED_NON_DAILY_PHOTO', $fresh->document_type);
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertNull($fresh->machine_id);
        $this->assertNull($fresh->extracted_date);
        $this->assertNull($fresh->extracted_time);

        $this->actingAs(\App\Models\User::factory()->create())
            ->get(route('ocr-reviews.show', $job))
            ->assertOk()
            ->assertSee('Không phải ảnh Daily')
            ->assertSee('NON_DAILY_OTHER');
    }

    public function test_protected_reviewed_confirmed_and_canonical_jobs_cannot_be_requested(): void
    {
        $human = $this->manualJob('human');
        $human->update(['machine_resolution_method' => 'HUMAN']);
        $reviewed = $this->manualJob('reviewed');
        $reviewed->update(['reviewed_at' => now()]);
        $confirmed = $this->manualJob('confirmed');
        $confirmed->update(['review_status' => 'APPROVED']);
        $corrected = $this->manualJob('corrected');
        $corrected->update(['review_status' => 'CORRECTED']);
        $canonical = $this->manualJob('canonical');
        $machine = $this->machine('T-CAN0717');
        $case = DailyPhotoCase::query()->create([
            'scope_key' => 'ai-rescue-protected',
            'machine_id' => $machine->id,
            'work_date' => '2026-09-20',
            'status' => DailyPhotoCase::STATUS_READY,
            'source_version' => 'test',
        ]);
        $canonical->update(['daily_photo_case_id' => $case->id]);

        foreach ([$human, $reviewed, $confirmed, $corrected, $canonical] as $job) {
            try {
                app(DailyPhotoAiRescueService::class)->request($job);
                $this->fail("Protected job {$job->id} was accepted.");
            } catch (ValidationException) {
                $this->assertSame('EXCEPTION', $job->fresh()->status);
            }
        }

        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 0);
    }

    public function test_photo_becoming_protected_after_request_is_never_overwritten(): void
    {
        $machine = $this->machine('T-XX0717');
        $job = $this->manualJob('protected-race');
        $attempt = $this->requestAndClaim($job, 'race-worker');
        $job->update([
            'review_status' => 'CORRECTED',
            'reviewed_at' => now(),
            'machine_id' => $machine->id,
            'machine_resolution_method' => 'HUMAN',
            'extracted_date' => '2026-09-20',
            'extracted_time' => '07:00:00',
        ]);
        $protectedFields = [
            'review_status', 'reviewed_at', 'machine_id', 'machine_resolution_method', 'extracted_date', 'extracted_time',
        ];
        $before = array_intersect_key($job->fresh()->getAttributes(), array_flip($protectedFields));

        $completed = app(DailyPhotoAiRescueService::class)->complete($attempt, $this->completionPayload(
            $attempt->attempts,
            [
                'classification' => 'DAILY_PHOTO',
                'machine' => 'T-XX0717',
                'capture_date' => '2026-09-28',
                'capture_time' => '10:31',
                'ambiguities' => [],
            ],
            'race-worker',
        ));

        $this->assertSame('SKIPPED_PROTECTED', $completed->final_resolution);
        $this->assertSame(
            $before,
            array_intersect_key($job->fresh()->getAttributes(), array_flip($protectedFields)),
        );
        $this->assertNull($job->fresh()->daily_photo_case_id);
    }

    public function test_duplicate_request_returns_the_single_active_attempt(): void
    {
        $job = $this->manualJob('double-click');
        $service = app(DailyPhotoAiRescueService::class);

        $first = $service->request($job);
        $second = $service->request($job->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 1);
        $this->assertDatabaseHas('daily_photo_ai_rescue_attempts', [
            'ocr_job_id' => $job->id,
            'active_key' => 'ACTIVE',
        ]);
    }

    public function test_provider_failure_retries_safely_then_becomes_terminal(): void
    {
        $job = $this->manualJob('provider-failure');
        $attempt = $this->requestAndClaim($job, 'failure-worker');
        $service = app(DailyPhotoAiRescueService::class);

        $retry = $service->fail($attempt, [
            'worker_id' => 'failure-worker',
            'attempt' => $attempt->attempts,
            'provider' => '9router-openai-compatible',
            'model' => 'vision-model',
            'error' => 'Vision API returned 503',
            'retryable' => true,
        ]);
        $this->assertSame('RETRY', $retry->status);
        $this->assertSame('ACTIVE', $retry->active_key);
        $duplicateRetry = $service->fail($retry, [
            'worker_id' => 'failure-worker',
            'attempt' => $retry->attempts,
            'provider' => '9router-openai-compatible',
            'model' => 'vision-model',
            'error' => 'Vision API returned 503',
            'retryable' => true,
        ]);
        $this->assertSame('RETRY', $duplicateRetry->status);
        $this->assertSame(1, $duplicateRetry->attempts);

        $reclaimed = $service->claim('failure-worker-2');
        $this->assertSame(2, $reclaimed->attempts);
        $failed = $service->fail($reclaimed, [
            'worker_id' => 'failure-worker-2',
            'attempt' => $reclaimed->attempts,
            'provider' => '9router-openai-compatible',
            'model' => 'vision-model',
            'error' => 'Invalid structured response',
            'retryable' => false,
        ]);

        $this->assertSame('FAILED', $failed->status);
        $this->assertSame('FAILED', $failed->final_resolution);
        $this->assertNull($failed->active_key);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
    }

    public function test_expired_lease_is_reclaimed_and_stale_worker_is_fenced(): void
    {
        $job = $this->manualJob('expired-lease');
        $first = $this->requestAndClaim($job, 'first-worker');
        $first->update(['lease_expires_at' => now()->subSecond()]);

        $second = app(DailyPhotoAiRescueService::class)->claim('second-worker');
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(2, $second?->attempts);
        $this->assertSame('second-worker', $second?->claimed_by);

        try {
            app(DailyPhotoAiRescueService::class)->complete($second, $this->completionPayload(
                1,
                [
                    'classification' => 'UNKNOWN',
                    'machine' => null,
                    'capture_date' => null,
                    'capture_time' => null,
                    'ambiguities' => ['uncertain'],
                ],
                'first-worker',
            ));
            $this->fail('A stale worker completed a reclaimed attempt.');
        } catch (ValidationException) {
            $this->assertSame('PROCESSING', $second->fresh()->status);
        }

        $completed = app(DailyPhotoAiRescueService::class)->complete($second, $this->completionPayload(
            2,
            [
                'classification' => 'UNKNOWN',
                'machine' => null,
                'capture_date' => null,
                'capture_time' => null,
                'ambiguities' => ['uncertain'],
            ],
            'second-worker',
        ));
        $this->assertSame('HUMAN_REQUIRED', $completed->final_resolution);
    }

    public function test_duplicate_completion_callback_is_idempotent(): void
    {
        $job = $this->manualJob('duplicate-callback');
        $attempt = $this->requestAndClaim($job, 'duplicate-worker');
        $payload = $this->completionPayload(
            $attempt->attempts,
            [
                'classification' => 'UNKNOWN',
                'machine' => null,
                'capture_date' => null,
                'capture_time' => null,
                'ambiguities' => ['uncertain'],
            ],
            'duplicate-worker',
        );

        $first = app(DailyPhotoAiRescueService::class)->complete($attempt, $payload);
        $second = app(DailyPhotoAiRescueService::class)->complete($attempt->fresh(), $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('HUMAN_REQUIRED', $second->final_resolution);
        $this->assertDatabaseCount('daily_photo_ai_rescue_attempts', 1);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
    }

    public function test_today_is_valid_even_when_received_at_is_earlier_but_tomorrow_is_future(): void
    {
        $this->machine('T-XX0717');
        $todayJob = $this->manualJob('today');
        $today = $this->requestAndClaim($todayJob, 'today-worker');
        $todayResult = app(DailyPhotoAiRescueService::class)->complete($today, $this->completionPayload(
            $today->attempts,
            [
                'classification' => 'DAILY_PHOTO',
                'machine' => 'T-XX0717',
                'capture_date' => '2026-09-28',
                'capture_time' => '23:59',
                'ambiguities' => [],
            ],
            'today-worker',
        ));
        $this->assertSame('RESOLVED', $todayResult->final_resolution);

        $futureJob = $this->manualJob('future');
        $future = $this->requestAndClaim($futureJob, 'future-worker');
        $futureResult = app(DailyPhotoAiRescueService::class)->complete($future, $this->completionPayload(
            $future->attempts,
            [
                'classification' => 'DAILY_PHOTO',
                'machine' => 'T-XX0717',
                'capture_date' => '2026-09-29',
                'capture_time' => '00:01',
                'ambiguities' => [],
            ],
            'future-worker',
        ));

        $this->assertSame('HUMAN_REQUIRED', $futureResult->final_resolution);
        $this->assertContains('CAPTURE_DATE_FUTURE', $futureResult->validation_outcome['reasons']);
        $this->assertSame('EXCEPTION', $futureJob->fresh()->status);
    }

    private function requestAndClaim(OcrJob $job, string $workerId)
    {
        $service = app(DailyPhotoAiRescueService::class);
        $requested = $service->request($job);
        $claimed = $service->claim($workerId);
        $this->assertSame($requested->id, $claimed?->id);
        $this->assertSame('PROCESSING', $claimed?->status);
        $this->assertSame($workerId, $claimed?->claimed_by);
        $this->assertFalse($claimed?->lease_expires_at?->isPast() ?? true, sprintf(
            'now=%s lease=%s',
            now()->toIso8601String(),
            $claimed?->lease_expires_at?->toIso8601String() ?? 'null',
        ));

        return $claimed;
    }

    private function completionPayload(int $attempt, array $result, string $workerId = 'vision-worker'): array
    {
        return [
            'worker_id' => $workerId,
            'attempt' => $attempt,
            'provider' => '9router-openai-compatible',
            'model' => 'vision-model',
            'prompt_version' => 'daily_photo_rescue_v1',
            'schema_version' => 'daily_photo_rescue_v1',
            'result' => $result,
            'raw_response' => json_encode($result, JSON_THROW_ON_ERROR),
            'usage' => [
                'prompt_tokens' => 11,
                'completion_tokens' => 7,
                'total_tokens' => 18,
            ],
        ];
    }

    private function manualJob(string $sender): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'ai-rescue',
            'message_id' => (string) Str::uuid(),
            'sender_id' => $sender,
            'sender_name' => $sender,
            'sent_at' => '2026-09-01 07:00:00',
            'received_at' => '2026-09-01 07:01:00',
            'status' => 'STORED',
        ]);
        $path = 'ai-rescue/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, "original-image-{$sender}");
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'daily.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', "original-image-{$sender}"),
            'mime_type' => 'image/jpeg',
            'byte_size' => strlen("original-image-{$sender}"),
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'document_type' => 'DAILY_TIMEMARK',
            'status' => 'EXCEPTION',
            'review_status' => 'PENDING',
            'attempts' => 3,
            'ocr_retry_attempts' => 1,
            'raw_text' => 'rapid legacy raw',
            'exceptions' => ['CAPTURE_TIME_AMBIGUOUS'],
            'daily_metadata' => [
                'ocr_candidate_summary' => [
                    'machine_candidates' => ['T-OLD9999'],
                    'date_candidates' => ['2026-08-31'],
                    'time_candidates' => ['07:00:00'],
                    'conflicts' => ['time'],
                ],
            ],
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
