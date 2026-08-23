<?php

namespace Tests\Feature\Api;

use App\Models\AiReconciliationJob;
use App\Models\JournalDocument;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openclaw.reconciliation_token' => 'test-openclaw-token',
            'openclaw.lease_seconds' => 600,
        ]);
        Storage::fake('local');
    }

    public function test_openclaw_agent_must_authenticate(): void
    {
        $this->postJson('/api/openclaw/v1/reconciliation/jobs/claim', [
            'worker_id' => 'openclaw-home-1',
            'work_date' => '2026-08-22',
        ])->assertUnauthorized();
    }

    public function test_agent_claims_reviewed_machine_day_with_daily_and_journal_evidence(): void
    {
        $machine = $this->createMachine('VT-XL1237');
        $dailyJob = $this->createOcrJob($machine, 'DAILY_TIMEMARK');
        $dailyJob->update([
            'status' => 'COMPLETED',
            'extracted_date' => '2026-08-22',
            'extracted_time' => '07:00:00',
            'asset_code' => $machine->asset_code,
            'confidence' => 0.96,
        ]);
        $dailyJob->update(['review_status' => 'AUTO_APPROVED']);

        $journalJob = $this->createOcrJob($machine, 'WEEKLY_JOURNAL');
        $journalJob->update(['status' => 'COMPLETED', 'asset_code' => $machine->asset_code]);
        $journalJob->update(['review_status' => 'APPROVED']);
        $document = JournalDocument::query()->create([
            'ocr_job_id' => $journalJob->id,
            'machine_id' => $machine->id,
            'asset_code' => $machine->asset_code,
            'confidence' => 0.93,
        ]);
        $document->rows()->create([
            'row_number' => 1,
            'work_date' => '2026-08-22',
            'start_time' => '07:00:00',
            'end_time' => '11:00:00',
            'total_minutes' => 240,
            'work_content' => 'San gạt mặt bằng',
            'work_location' => 'Hạ Long Xanh',
            'confidence' => 0.92,
        ]);

        $response = $this->withToken('test-openclaw-token')
            ->postJson('/api/openclaw/v1/reconciliation/jobs/claim', [
                'worker_id' => 'openclaw-home-1',
                'work_date' => '2026-08-22',
                'limit' => 10,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'jobs')
            ->assertJsonPath('jobs.0.machine.asset_code', 'VT-XL1237')
            ->assertJsonCount(1, 'jobs.0.daily_images')
            ->assertJsonCount(1, 'jobs.0.journal_rows')
            ->assertJsonPath('jobs.0.journal_rows.0.total_minutes', 240);

        $imageUrl = $response->json('jobs.0.daily_images.0.image_url');
        $this->get($imageUrl)->assertUnauthorized();
        $this->withToken('test-openclaw-token')->get($imageUrl)
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_unreviewed_ocr_data_is_not_enqueued(): void
    {
        $machine = $this->createMachine('VT-XL1238');
        $job = $this->createOcrJob($machine, 'DAILY_TIMEMARK');
        $job->update([
            'status' => 'EXCEPTION',
            'review_status' => 'PENDING',
            'extracted_date' => '2026-08-22',
        ]);

        $this->withToken('test-openclaw-token')
            ->postJson('/api/openclaw/v1/reconciliation/jobs/claim', [
                'worker_id' => 'openclaw-home-1',
                'work_date' => '2026-08-22',
            ])
            ->assertNoContent();

        $this->assertDatabaseCount('ai_reconciliation_jobs', 0);
    }

    public function test_agent_result_is_immutable_and_idempotent(): void
    {
        $job = $this->claimDailyJob();
        $submissionUuid = (string) Str::uuid();
        $payload = [
            'worker_id' => 'openclaw-home-1',
            'submission_uuid' => $submissionUuid,
            'outcome' => 'WARNING',
            'summary' => 'Ảnh hằng ngày chưa có dòng nhật trình tương ứng.',
            'confidence' => 0.91,
            'agent_name' => 'mmtb-reconciliation-agent',
            'model' => '9router-model',
            'findings' => [[
                'code' => 'MISSING_JOURNAL_ROW',
                'severity' => 'WARNING',
                'title' => 'Thiếu nhật trình tuần',
                'description' => 'Có ảnh hằng ngày nhưng chưa có dòng nhật trình.',
                'evidence' => ['daily_image_count' => 1, 'journal_row_count' => 0],
                'suggested_action' => 'Kiểm tra nhật trình tuần hoặc yêu cầu bổ sung.',
                'confidence' => 0.91,
            ]],
        ];

        $url = "/api/openclaw/v1/reconciliation/jobs/{$job->id}/complete";
        $this->withToken('test-openclaw-token')->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('submission.outcome', 'WARNING')
            ->assertJsonPath('submission.findings.0.code', 'MISSING_JOURNAL_ROW');

        $this->withToken('test-openclaw-token')->postJson($url, $payload)->assertOk();

        $this->assertDatabaseCount('ai_reconciliation_submissions', 1);
        $this->assertDatabaseCount('ai_reconciliation_findings', 1);
        $this->assertDatabaseHas('ai_reconciliation_jobs', [
            'id' => $job->id,
            'status' => 'COMPLETED',
        ]);
    }

    public function test_retryable_agent_failure_returns_job_to_queue(): void
    {
        $job = $this->claimDailyJob();

        $this->withToken('test-openclaw-token')
            ->postJson("/api/openclaw/v1/reconciliation/jobs/{$job->id}/fail", [
                'worker_id' => 'openclaw-home-1',
                'error' => '9router timeout',
                'retryable' => true,
            ])
            ->assertOk()
            ->assertJsonPath('job.status', 'RETRY');

        $this->withToken('test-openclaw-token')
            ->postJson('/api/openclaw/v1/reconciliation/jobs/claim', [
                'worker_id' => 'openclaw-home-2',
                'work_date' => '2026-08-22',
            ])
            ->assertOk()
            ->assertJsonPath('jobs.0.id', $job->id)
            ->assertJsonPath('jobs.0.attempts', 2);
    }

    public function test_new_reviewed_evidence_reopens_completed_machine_day_without_deleting_history(): void
    {
        $job = $this->claimDailyJob();
        $this->withToken('test-openclaw-token')
            ->postJson("/api/openclaw/v1/reconciliation/jobs/{$job->id}/complete", [
                'worker_id' => 'openclaw-home-1',
                'submission_uuid' => (string) Str::uuid(),
                'outcome' => 'WARNING',
                'agent_name' => 'mmtb-reconciliation-agent',
                'findings' => [],
            ])
            ->assertOk();

        $machine = $job->machine;
        $journalJob = $this->createOcrJob($machine, 'WEEKLY_JOURNAL');
        $journalJob->update(['status' => 'COMPLETED', 'asset_code' => $machine->asset_code]);
        $journalJob->update(['review_status' => 'APPROVED']);
        $document = JournalDocument::query()->create([
            'ocr_job_id' => $journalJob->id,
            'machine_id' => $machine->id,
            'asset_code' => $machine->asset_code,
            'confidence' => 0.94,
        ]);
        $document->rows()->create([
            'row_number' => 1,
            'work_date' => '2026-08-22',
            'start_time' => '07:00:00',
            'end_time' => '11:00:00',
            'total_minutes' => 240,
            'work_content' => 'Thi công mặt bằng',
            'confidence' => 0.93,
        ]);

        $this->withToken('test-openclaw-token')
            ->postJson('/api/openclaw/v1/reconciliation/jobs/claim', [
                'worker_id' => 'openclaw-home-2',
                'work_date' => '2026-08-22',
            ])
            ->assertOk()
            ->assertJsonPath('jobs.0.id', $job->id)
            ->assertJsonCount(1, 'jobs.0.journal_rows');

        $this->assertDatabaseCount('ai_reconciliation_jobs', 1);
        $this->assertDatabaseCount('ai_reconciliation_submissions', 1);
    }

    private function claimDailyJob(): AiReconciliationJob
    {
        $machine = $this->createMachine('VT-XL'.fake()->unique()->numberBetween(2000, 9999));
        $ocrJob = $this->createOcrJob($machine, 'DAILY_TIMEMARK');
        $ocrJob->update([
            'status' => 'COMPLETED',
            'extracted_date' => '2026-08-22',
            'extracted_time' => '07:00:00',
            'asset_code' => $machine->asset_code,
            'confidence' => 0.95,
        ]);
        $ocrJob->update(['review_status' => 'APPROVED']);

        $response = $this->withToken('test-openclaw-token')
            ->postJson('/api/openclaw/v1/reconciliation/jobs/claim', [
                'worker_id' => 'openclaw-home-1',
                'work_date' => '2026-08-22',
            ])
            ->assertOk();

        return AiReconciliationJob::query()->findOrFail($response->json('jobs.0.id'));
    }

    private function createMachine(string $assetCode): Machine
    {
        return Machine::query()->create([
            'asset_code' => $assetCode,
            'company' => 'VINALPHA',
            'chassis_no' => 'CHASSIS-'.Str::uuid(),
            'status' => 'ACTIVE',
        ]);
    }

    private function createOcrJob(Machine $machine, string $documentType): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'group-1',
            'message_id' => (string) Str::uuid(),
            'sender_id' => 'sender-1',
            'sender_name' => 'Người vận hành',
            'sent_at' => '2026-08-22T07:00:00+07:00',
            'received_at' => now(),
            'status' => 'STORED',
        ]);
        $path = 'zalo/2026/08/22/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'image-bytes');
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'source.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', 'image-bytes-'.$path),
            'mime_type' => 'image/jpeg',
            'byte_size' => 11,
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'machine_id' => $machine->id,
            'document_type' => $documentType,
            'status' => 'PENDING',
        ]);
    }
}
