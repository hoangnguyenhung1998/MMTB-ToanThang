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
        $this->withHeader('Authorization', '')->get($imageUrl)->assertUnauthorized();
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

    public function test_missing_journal_waits_for_evidence_without_calling_openclaw(): void
    {
        $machine = $this->createMachine('VT-XL1239');
        $this->createReviewedDaily($machine, '2026-08-22', '07:00:00');

        $this->claimForDate('2026-08-22')->assertNoContent();

        $this->assertDatabaseHas('ai_reconciliation_jobs', [
            'machine_id' => $machine->id,
            'work_date' => '2026-08-22',
            'status' => 'WAITING_EVIDENCE',
            'attempts' => 0,
            'error_message' => 'JOURNAL_ROW_MISSING',
        ]);
        $this->assertDatabaseCount('ai_reconciliation_submissions', 0);
    }

    public function test_new_journal_reopens_waiting_job_for_rule_or_openclaw_processing(): void
    {
        $machine = $this->createMachine('VT-XL1240');
        $this->createReviewedDaily($machine, '2026-08-22', '07:00:00');
        $this->claimForDate('2026-08-22')->assertNoContent();

        $this->createApprovedJournalRow($machine, '2026-08-22', '07:00:00', '11:00:00', 240);

        $this->claimForDate('2026-08-22')
            ->assertOk()
            ->assertJsonPath('jobs.0.machine.asset_code', 'VT-XL1240');
        $this->assertDatabaseHas('ai_reconciliation_jobs', [
            'machine_id' => $machine->id,
            'status' => 'PROCESSING',
        ]);
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

    public function test_rules_engine_completes_a_matching_machine_day_without_openclaw(): void
    {
        $machine = $this->createMachine('VT-XL4101');
        $this->createReviewedDaily($machine, '2026-08-22', '07:00:00');
        $this->createReviewedDaily($machine, '2026-08-22', '11:00:00');
        $this->createApprovedJournalRow($machine, '2026-08-22', '07:00:00', '11:00:00', 240);

        $this->claimForDate('2026-08-22')->assertNoContent();

        $this->assertDatabaseHas('ai_reconciliation_jobs', ['status' => 'COMPLETED']);
        $this->assertDatabaseHas('ai_reconciliation_submissions', [
            'outcome' => 'MATCHED',
            'agent_name' => 'mmtb-rules-engine',
        ]);
        $this->assertDatabaseCount('ai_reconciliation_findings', 0);
    }

    public function test_rules_engine_classifies_time_warning_and_critical_thresholds(): void
    {
        $matchedMachine = $this->createMachine('VT-XL4107');
        $this->createReviewedDaily($matchedMachine, '2026-08-22', '07:30:00');
        $this->createReviewedDaily($matchedMachine, '2026-08-22', '11:30:00');
        $this->createApprovedJournalRow($matchedMachine, '2026-08-22', '07:00:00', '11:00:00', 240);

        $warningMachine = $this->createMachine('VT-XL4102');
        $this->createReviewedDaily($warningMachine, '2026-08-22', '08:00:00');
        $this->createReviewedDaily($warningMachine, '2026-08-22', '12:00:00');
        $this->createApprovedJournalRow($warningMachine, '2026-08-22', '07:00:00', '11:00:00', 240);

        $criticalMachine = $this->createMachine('VT-XL4103');
        $this->createReviewedDaily($criticalMachine, '2026-08-22', '08:01:00');
        $this->createReviewedDaily($criticalMachine, '2026-08-22', '12:01:00');
        $this->createApprovedJournalRow($criticalMachine, '2026-08-22', '07:00:00', '11:00:00', 240);

        $this->claimForDate('2026-08-22')->assertNoContent();

        $this->assertDatabaseHas('ai_reconciliation_submissions', ['outcome' => 'MATCHED']);
        $this->assertDatabaseHas('ai_reconciliation_submissions', ['outcome' => 'WARNING']);
        $this->assertDatabaseHas('ai_reconciliation_submissions', ['outcome' => 'EXCEPTION']);
        $this->assertDatabaseHas('ai_reconciliation_findings', [
            'code' => 'TIME_DIFFERENCE',
            'severity' => 'WARNING',
        ]);
        $this->assertDatabaseHas('ai_reconciliation_findings', [
            'code' => 'TIME_DIFFERENCE',
            'severity' => 'CRITICAL',
        ]);
    }

    public function test_missing_end_image_remains_available_for_openclaw(): void
    {
        $machine = $this->createMachine('VT-XL4104');
        $this->createReviewedDaily($machine, '2026-08-22', '07:00:00');
        $this->createApprovedJournalRow($machine, '2026-08-22', '07:00:00', '11:00:00', 240);

        $this->claimForDate('2026-08-22')
            ->assertOk()
            ->assertJsonPath('jobs.0.machine.asset_code', 'VT-XL4104');

        $this->assertDatabaseCount('ai_reconciliation_submissions', 0);
    }

    public function test_rules_engine_matches_an_overnight_shift_to_the_previous_work_date(): void
    {
        $machine = $this->createMachine('VT-XL4105');
        $this->createReviewedDaily($machine, '2026-08-22', '22:00:00');
        $this->createReviewedDaily($machine, '2026-08-23', '02:00:00');
        $this->createApprovedJournalRow($machine, '2026-08-22', '22:00:00', '02:00:00', 240);

        $this->claimForDate('2026-08-22')->assertNoContent();

        $this->assertDatabaseHas('ai_reconciliation_submissions', ['outcome' => 'MATCHED']);
    }

    public function test_rules_engine_is_idempotent_for_the_same_evidence_signature(): void
    {
        $machine = $this->createMachine('VT-XL4106');
        $this->createReviewedDaily($machine, '2026-08-22', '07:00:00');
        $this->createReviewedDaily($machine, '2026-08-22', '11:00:00');
        $this->createApprovedJournalRow($machine, '2026-08-22', '07:00:00', '11:00:00', 240);

        $this->claimForDate('2026-08-22')->assertNoContent();
        $this->claimForDate('2026-08-22')->assertNoContent();

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
        $this->createApprovedJournalRow($machine, '2026-08-22', '07:00:00', '11:00:00', 240);

        $response = $this->withToken('test-openclaw-token')
            ->postJson('/api/openclaw/v1/reconciliation/jobs/claim', [
                'worker_id' => 'openclaw-home-1',
                'work_date' => '2026-08-22',
            ])
            ->assertOk();

        return AiReconciliationJob::query()->findOrFail($response->json('jobs.0.id'));
    }

    private function claimForDate(string $workDate)
    {
        return $this->withToken('test-openclaw-token')
            ->postJson('/api/openclaw/v1/reconciliation/jobs/claim', [
                'worker_id' => 'openclaw-home-1',
                'work_date' => $workDate,
                'limit' => 20,
            ]);
    }

    private function createReviewedDaily(Machine $machine, string $date, string $time): OcrJob
    {
        $job = $this->createOcrJob($machine, 'DAILY_TIMEMARK');
        $job->update([
            'status' => 'COMPLETED',
            'extracted_date' => $date,
            'extracted_time' => $time,
            'asset_code' => $machine->asset_code,
            'confidence' => 0.96,
        ]);
        $job->update(['review_status' => 'APPROVED']);

        return $job;
    }

    private function createApprovedJournalRow(
        Machine $machine,
        string $date,
        string $startTime,
        string $endTime,
        int $totalMinutes,
    ): void {
        $job = $this->createOcrJob($machine, 'WEEKLY_JOURNAL');
        $job->update([
            'status' => 'COMPLETED',
            'asset_code' => $machine->asset_code,
        ]);
        $job->update(['review_status' => 'APPROVED']);
        $document = JournalDocument::query()->create([
            'ocr_job_id' => $job->id,
            'machine_id' => $machine->id,
            'asset_code' => $machine->asset_code,
            'confidence' => 0.95,
        ]);
        $document->rows()->create([
            'row_number' => 1,
            'work_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'total_minutes' => $totalMinutes,
            'work_content' => 'Thi công mặt bằng',
            'confidence' => 0.95,
        ]);
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
