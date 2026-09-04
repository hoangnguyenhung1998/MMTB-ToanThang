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

        $this->assertStringStartsWith('/', $response->json('job.image_url'));

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

    public function test_daily_image_submitted_one_day_late_is_accepted(): void
    {
        Machine::query()->create([
            'asset_code' => 'VT-XL5024',
            'company' => 'VINALPHA',
            'chassis_no' => 'TEST-CHASSIS-LATE-1',
            'status' => 'ACTIVE',
        ]);
        $job = $this->createJob();
        $this->claim($job);

        $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/complete", [
                'worker_id' => 'worker-1',
                'date' => '2026-08-18',
                'time' => '14:02:00',
                'asset_code' => 'VT-XL5024',
                'confidence' => 0.95,
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'COMPLETED')
            ->assertJsonPath('job.exceptions', null);
    }

    public function test_daily_image_can_be_submitted_for_any_past_date(): void
    {
        Machine::query()->create([
            'asset_code' => 'VT-XL5024',
            'company' => 'VINALPHA',
            'chassis_no' => 'TEST-CHASSIS-LATE-2',
            'status' => 'ACTIVE',
        ]);
        $job = $this->createJob();
        $this->claim($job);

        $response = $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/complete", [
                'worker_id' => 'worker-1',
                'date' => '2026-08-17',
                'time' => '14:02:00',
                'asset_code' => 'VT-XL5024',
                'confidence' => 0.95,
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'COMPLETED')
            ->assertJsonPath('job.exceptions', null);
    }

    public function test_daily_image_can_be_submitted_for_any_future_date(): void
    {
        Machine::query()->create([
            'asset_code' => 'VT-XL5024',
            'company' => 'VINALPHA',
            'chassis_no' => 'TEST-CHASSIS-FUTURE',
            'status' => 'ACTIVE',
        ]);
        $job = $this->createJob();
        $this->claim($job);

        $response = $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/complete", [
                'worker_id' => 'worker-1',
                'date' => '2026-08-20',
                'time' => '14:02:00',
                'asset_code' => 'VT-XL5024',
                'confidence' => 0.95,
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'COMPLETED')
            ->assertJsonPath('job.exceptions', null);
    }

    public function test_uncertain_or_invalid_result_becomes_exception(): void
    {
        $job = $this->createJob();
        $this->claim($job);

        $response = $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/complete", [
                'worker_id' => 'worker-1',
                'date' => '2026-08-17',
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

    public function test_worker_claims_only_supported_document_types(): void
    {
        $unknownJob = $this->createJob();
        $dailyJob = $this->createJob();
        $dailyJob->update(['document_type' => 'DAILY_TIMEMARK']);

        $this->withToken('test-ocr-token')
            ->postJson('/api/ocr/v1/jobs/claim', [
                'worker_id' => 'rapid-ocr-1',
                'document_types' => ['DAILY_TIMEMARK'],
            ])
            ->assertOk()
            ->assertJsonPath('job.id', $dailyJob->id)
            ->assertJsonPath('job.document_type', 'DAILY_TIMEMARK');

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $unknownJob->id,
            'status' => 'PENDING',
            'document_type' => 'UNKNOWN',
        ]);
    }

    public function test_classifier_routes_an_unknown_job_back_to_the_typed_queue(): void
    {
        $job = $this->createJob();

        $this->withToken('test-ocr-token')
            ->postJson('/api/ocr/v1/jobs/claim', [
                'worker_id' => 'classifier-1',
                'document_types' => ['UNKNOWN'],
            ])
            ->assertOk()
            ->assertJsonPath('job.id', $job->id);

        $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/classify", [
                'worker_id' => 'classifier-1',
                'document_type' => 'WEEKLY_JOURNAL',
                'confidence' => 0.98,
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'PENDING')
            ->assertJsonPath('job.document_type', 'WEEKLY_JOURNAL');

        $this->withToken('test-ocr-token')
            ->postJson('/api/ocr/v1/jobs/claim', [
                'worker_id' => 'openclaw-1',
                'document_types' => ['WEEKLY_JOURNAL'],
            ])
            ->assertOk()
            ->assertJsonPath('job.id', $job->id);
    }

    public function test_uncertain_document_classification_becomes_exception(): void
    {
        $job = $this->createJob();
        $this->claim($job);

        $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/classify", [
                'worker_id' => 'worker-1',
                'document_type' => 'UNKNOWN',
                'confidence' => 0.45,
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'EXCEPTION')
            ->assertJsonPath('job.document_type', 'UNKNOWN')
            ->assertJsonPath('job.exceptions.0', 'UNCLASSIFIED_DOCUMENT');
    }

    public function test_worker_downloads_machine_catalog(): void
    {
        Machine::query()->create([
            'asset_code' => 'VT-LU0216',
            'company' => 'VINALPHA',
            'chassis_no' => 'TEST-CATALOG-1',
            'status' => 'ACTIVE',
        ]);

        $this->getJson('/api/ocr/v1/machines')->assertUnauthorized();

        $this->withToken('test-ocr-token')
            ->getJson('/api/ocr/v1/machines')
            ->assertOk()
            ->assertJsonPath('machines.0.asset_code', 'VT-LU0216')
            ->assertJsonPath('machines.0.status', 'ACTIVE');
    }

    public function test_weekly_journal_stores_multiple_rows_and_keeps_source_image_link(): void
    {
        $machine = Machine::query()->create([
            'asset_code' => 'T-XL0354',
            'company' => 'VINCONS',
            'chassis_no' => 'TEST-CHASSIS-JOURNAL',
            'status' => 'ACTIVE',
        ]);
        $job = $this->createJob();
        $job->update(['document_type' => 'WEEKLY_JOURNAL']);
        $this->claim($job);

        $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/complete-journal", [
                'worker_id' => 'worker-1',
                'asset_code' => 't-xl0354',
                'confidence' => 0.94,
                'raw_text' => 'weekly journal OCR text',
                'rows' => [
                    [
                        'row_number' => 1,
                        'work_date' => '2026-08-17',
                        'start_time' => '07:00:00',
                        'end_time' => '11:00:00',
                        'total_minutes' => 240,
                        'work_content' => 'Thi cong dao dat',
                        'work_location' => 'Ha Long Xanh',
                        'confidence' => 0.93,
                    ],
                    [
                        'row_number' => 2,
                        'work_date' => '2026-08-18',
                        'total_minutes' => 480,
                        'work_content' => 'San gat mat bang; Chờ dầu',
                        'error_explanation' => 'Chờ dầu',
                        'work_location' => 'Ha Long Xanh',
                        'confidence' => 0.91,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'COMPLETED')
            ->assertJsonCount(2, 'job.journal_document.rows');

        $this->assertDatabaseHas('journal_documents', [
            'ocr_job_id' => $job->id,
            'machine_id' => $machine->id,
            'asset_code' => 'T-XL0354',
        ]);
        $this->assertDatabaseHas('journal_rows', [
            'work_date' => '2026-08-18',
            'work_content' => 'San gat mat bang; Chờ dầu',
            'error_explanation' => 'Chờ dầu',
        ]);

        $storedJob = OcrJob::query()
            ->where('machine_id', $machine->id)
            ->whereHas('journalDocument.rows', fn ($query) => $query->whereDate('work_date', '2026-08-18'))
            ->with('attachment')
            ->firstOrFail();

        $this->assertSame($job->zalo_attachment_id, $storedJob->attachment->id);
        Storage::disk($storedJob->attachment->storage_disk)
            ->assertExists($storedJob->attachment->storage_path);
    }

    public function test_weekly_journal_with_uncertain_row_becomes_exception(): void
    {
        $job = $this->createJob();
        $job->update(['document_type' => 'WEEKLY_JOURNAL']);
        $this->claim($job);

        $this->withToken('test-ocr-token')
            ->postJson("/api/ocr/v1/jobs/{$job->id}/complete-journal", [
                'worker_id' => 'worker-1',
                'confidence' => 0.95,
                'rows' => [[
                    'row_number' => 1,
                    'confidence' => 0.40,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'EXCEPTION');

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id,
            'status' => 'EXCEPTION',
        ]);
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
