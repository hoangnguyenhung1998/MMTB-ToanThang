<?php

namespace Tests\Feature\Api;

use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Ho_Chi_Minh',
            'ocr.worker_token' => 'test-ocr-token',
            'ocr.lease_seconds' => 300,
            'ocr.minimum_confidence' => 0.80,
        ]);
        Storage::fake('local');
    }

    public function test_worker_must_authenticate(): void
    {
        $this->postJson('/api/ocr/v1/jobs/claim', ['worker_id' => 'worker-1'])
            ->assertUnauthorized();
    }

    public function test_worker_claims_one_pending_job_and_downloads_private_image(): void
    {
        $job = $this->createJob();

        $response = $this->withToken('test-ocr-token')
            ->postJson('/api/ocr/v1/jobs/claim', ['worker_id' => 'worker-1'])
            ->assertOk()
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('job.attempts', 1);

        $this->withToken('test-ocr-token')
            ->get($response->json('job.image_url'))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->withToken('test-ocr-token')
            ->postJson('/api/ocr/v1/jobs/claim', ['worker_id' => 'worker-2'])
            ->assertNoContent();
    }

    public function test_successful_result_is_matched_to_machine_and_classified(): void
    {
        Machine::query()->create([
            'asset_code' => 'T-XL0354',
            'company' => 'VINCONS',
            'chassis_no' => 'TEST-CHASSIS-1',
            'status' => 'ACTIVE',
        ]);
        $job = $this->createJob();
        $this->claim($job);

        $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/complete", [
                'worker_id' => 'worker-1',
                'date' => '2026-08-19',
                'time' => '16:45:00',
                'asset_code' => 't-xl0354',
                'operator_name' => 'Le The Vy',
                'phone' => '0367756204',
                'work_location' => 'Ha Long Xanh',
                'confidence' => 0.96,
                'raw_text' => 'OCR text',
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'COMPLETED')
            ->assertJsonPath('job.shift', 'AFTERNOON_OT')
            ->assertJsonPath('job.asset_code', 'T-XL0354');

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => 'COMPLETED',
            'shift' => 'AFTERNOON_OT',
        ]);
    }

    public function test_uncertain_or_invalid_result_becomes_exception(): void
    {
        $job = $this->createJob();
        $this->claim($job);

        $response = $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/complete", [
                'worker_id' => 'worker-1',
                'date' => '2026-08-18',
                'time' => '06:00:00',
                'asset_code' => 'T-UNKNOWN',
                'confidence' => 0.50,
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'EXCEPTION');

        $this->assertEqualsCanonicalizing([
            'LOW_CONFIDENCE',
            'UNCLASSIFIED_TIME',
            'UNKNOWN_ASSET_CODE',
            'WRONG_DATE',
        ], $response->json('job.exceptions'));
    }

    public function test_retryable_worker_failure_returns_job_to_queue(): void
    {
        $job = $this->createJob();
        $this->claim($job);

        $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/fail", [
                'worker_id' => 'worker-1',
                'error' => 'Vision API timeout',
                'retryable' => true,
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'RETRY');

        $this->withToken('test-ocr-token')
            ->postJson('/api/ocr/v1/jobs/claim', ['worker_id' => 'worker-2'])
            ->assertOk()
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('job.attempts', 2);
    }

    private function claim(OcrJob $job): void
    {
        $this->withToken('test-ocr-token')
            ->postJson('/api/ocr/v1/jobs/claim', ['worker_id' => 'worker-1'])
            ->assertOk()
            ->assertJsonPath('job.id', $job->id);
    }

    private function createJob(): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'group-1',
            'message_id' => fake()->uuid(),
            'sender_id' => 'sender-1',
            'sender_name' => 'T-XL0354 | Le The Vy',
            'sent_at' => '2026-08-19T09:48:40+00:00',
            'received_at' => now(),
            'status' => 'STORED',
        ]);
        $path = 'zalo/2026/08/19/test-'.fake()->uuid().'.jpg';
        Storage::disk('local')->put($path, 'image-bytes');
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'timemark.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', 'image-bytes'),
            'mime_type' => 'image/jpeg',
            'byte_size' => 11,
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'status' => 'PENDING',
        ]);
    }
}
