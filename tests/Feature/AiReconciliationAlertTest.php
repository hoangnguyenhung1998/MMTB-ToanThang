<?php

namespace Tests\Feature;

use App\Models\AiReconciliationAlert;
use App\Models\AiReconciliationJob;
use App\Models\AiReconciliationSubmission;
use App\Models\Machine;
use App\Services\AiReconciliationAlertDispatcher;
use App\Services\AiReconciliationAlertRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiReconciliationAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.enabled' => true,
            'telegram.bot_token' => 'test-bot-token',
            'telegram.chat_id' => '123456',
            'mmtb.reconciliation_alerts.waiting_evidence_hours' => 2,
            'mmtb.reconciliation_alerts.retry_minutes' => 5,
            'mmtb.reconciliation_alerts.max_attempts' => 5,
            'mmtb.reconciliation_alerts.dashboard_url' => 'https://mmtb.example/ai-reconciliation',
        ]);

    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_exception_is_sent_immediately_and_not_recorded_twice(): void
    {
        $this->fakeTelegramSuccess();
        $job = $this->createJob('VT-XL1501');
        $submission = $this->createSubmission($job, 'EXCEPTION', 'Lệch thời gian nghiêm trọng.');

        $this->assertDatabaseCount('ai_reconciliation_alerts', 1);

        app(AiReconciliationAlertRecorder::class)->recordSubmission($submission);
        $this->assertDatabaseCount('ai_reconciliation_alerts', 1);

        $result = app(AiReconciliationAlertDispatcher::class)->dispatchUrgent();

        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseHas('ai_reconciliation_alerts', [
            'kind' => 'EXCEPTION',
            'status' => 'SENT',
        ]);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '123456'
            && str_contains($request['text'], 'VT-XL1501')
            && str_contains($request['text'], '/ai-reconciliation/'.$job->id));
    }

    public function test_warning_waits_for_the_thirty_minute_batch(): void
    {
        $this->fakeTelegramSuccess();
        $job = $this->createJob('VT-XL1502');
        $this->createSubmission($job, 'WARNING', 'Có dữ liệu OCR cần kiểm tra.');

        $urgent = app(AiReconciliationAlertDispatcher::class)->dispatchUrgent();

        $this->assertSame(0, $urgent['sent']);
        Http::assertNothingSent();

        $warnings = app(AiReconciliationAlertDispatcher::class)->dispatchWarnings();

        $this->assertSame(1, $warnings['sent']);
        $this->assertDatabaseHas('ai_reconciliation_alerts', [
            'kind' => 'WARNING',
            'status' => 'SENT',
        ]);
    }

    public function test_waiting_evidence_is_only_sent_after_two_hours(): void
    {
        $this->fakeTelegramSuccess();
        CarbonImmutable::setTestNow('2026-08-25 08:00:00');
        $job = $this->createJob('VT-XL1503', 'WAITING_EVIDENCE');

        app(AiReconciliationAlertDispatcher::class)->dispatchUrgent();
        $this->assertDatabaseMissing('ai_reconciliation_alerts', ['kind' => 'WAITING_EVIDENCE']);

        AiReconciliationJob::query()->whereKey($job->id)->update([
            'updated_at' => now()->subHours(3),
        ]);

        $result = app(AiReconciliationAlertDispatcher::class)->dispatchUrgent();

        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseHas('ai_reconciliation_alerts', [
            'kind' => 'WAITING_EVIDENCE',
            'status' => 'SENT',
        ]);
    }

    public function test_failed_delivery_is_retried_without_creating_a_duplicate(): void
    {
        CarbonImmutable::setTestNow('2026-08-25 08:00:00');
        Http::fakeSequence()
            ->push(['ok' => false], 500)
            ->push(['ok' => true, 'result' => ['message_id' => 2]]);
        $job = $this->createJob('VT-XL1504');

        $job->update([
            'status' => 'FAILED',
            'failed_at' => now(),
            'error_message' => 'OpenClaw không phản hồi.',
        ]);

        app(AiReconciliationAlertDispatcher::class)->dispatchUrgent();

        $this->assertDatabaseHas('ai_reconciliation_alerts', [
            'kind' => 'FAILED',
            'status' => 'RETRY',
            'attempts' => 1,
        ]);

        CarbonImmutable::setTestNow('2026-08-25 08:06:00');

        app(AiReconciliationAlertDispatcher::class)->dispatchUrgent();

        $this->assertDatabaseCount('ai_reconciliation_alerts', 1);
        $this->assertDatabaseHas('ai_reconciliation_alerts', [
            'kind' => 'FAILED',
            'status' => 'SENT',
        ]);
    }

    public function test_daily_digest_summarizes_the_previous_work_date_once(): void
    {
        $this->fakeTelegramSuccess();
        CarbonImmutable::setTestNow('2026-08-25 07:00:00');
        $matched = $this->createJob('VT-XL1505', 'COMPLETED', '2026-08-24');
        $warning = $this->createJob('VT-XL1506', 'COMPLETED', '2026-08-24');
        $this->createSubmission($matched, 'MATCHED', 'Đã khớp.');
        $this->createSubmission($warning, 'WARNING', 'Cần kiểm tra.');

        $dispatcher = app(AiReconciliationAlertDispatcher::class);
        $dispatcher->dispatchDailyDigest();
        $dispatcher->dispatchDailyDigest();

        $this->assertDatabaseCount('ai_reconciliation_alerts', 2);
        $this->assertDatabaseHas('ai_reconciliation_alerts', [
            'fingerprint' => 'daily-digest:2026-08-24',
            'status' => 'SENT',
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Tổng job: <b>2</b>')
            && str_contains($request['text'], 'Đã khớp: <b>1</b>')
            && str_contains($request['text'], 'Cảnh báo: <b>1</b>'));
    }

    private function createJob(
        string $assetCode,
        string $status = 'PENDING',
        string $workDate = '2026-08-24',
    ): AiReconciliationJob {
        $machine = Machine::query()->create([
            'asset_code' => $assetCode,
            'company' => 'VINALPHA',
            'chassis_no' => 'CHASSIS-'.Str::uuid(),
            'status' => 'ACTIVE',
        ]);

        return AiReconciliationJob::query()->create([
            'machine_id' => $machine->id,
            'work_date' => $workDate,
            'source_signature' => hash('sha256', $assetCode.$workDate),
            'status' => $status,
        ]);
    }

    private function createSubmission(
        AiReconciliationJob $job,
        string $outcome,
        string $summary,
    ): AiReconciliationSubmission {
        return $job->submissions()->create([
            'submission_uuid' => (string) Str::uuid(),
            'outcome' => $outcome,
            'summary' => $summary,
            'confidence' => 0.96,
            'agent_name' => 'test-agent',
            'submitted_at' => now(),
        ]);
    }

    private function fakeTelegramSuccess(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }
}
