<?php

namespace Tests\Feature;

use App\Models\AiReconciliationJob;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiReconciliationDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openclaw.reconciliation_token' => 'test-openclaw-token',
            'openclaw.lease_seconds' => 600,
        ]);
    }

    public function test_dashboard_lists_matched_jobs_without_findings(): void
    {
        $job = $this->createJob('VT-XL1401', '2026-08-23', 'COMPLETED');
        $job->submissions()->create([
            'submission_uuid' => (string) Str::uuid(),
            'outcome' => 'MATCHED',
            'summary' => 'Ảnh hằng ngày khớp với nhật trình.',
            'confidence' => 1,
            'agent_name' => 'mmtb-rules-engine',
            'submitted_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/ai-reconciliation?outcome=MATCHED')
            ->assertOk()
            ->assertSee('VT-XL1401')
            ->assertSee('Đã khớp');
    }

    public function test_dashboard_filters_waiting_evidence_as_job_status(): void
    {
        $waitingJob = $this->createJob('VT-XL1402', '2026-08-23', 'WAITING_EVIDENCE');
        $this->createJob('VT-XL1403', '2026-08-23', 'COMPLETED');

        $this->actingAs(User::factory()->create())
            ->get('/ai-reconciliation?status=WAITING_EVIDENCE')
            ->assertOk()
            ->assertSee('VT-XL1402')
            ->assertViewHas('jobs', fn ($jobs): bool => $jobs->count() === 1
                && $jobs->first()->is($waitingJob));
    }

    public function test_job_detail_displays_findings_and_submission_history(): void
    {
        $job = $this->createJob('VT-XL1406', '2026-08-23', 'COMPLETED');
        $submission = $job->submissions()->create([
            'submission_uuid' => (string) Str::uuid(),
            'outcome' => 'EXCEPTION',
            'summary' => 'Phát hiện lệch thời gian nghiêm trọng.',
            'confidence' => 0.94,
            'agent_name' => 'openclaw-home-1',
            'submitted_at' => now(),
        ]);
        $submission->findings()->create([
            'code' => 'END_TIME_CRITICAL',
            'severity' => 'CRITICAL',
            'title' => 'Giờ cuối ca lệch trên 60 phút',
            'description' => 'Ảnh cuối ca không khớp nhật trình.',
            'evidence' => ['difference_minutes' => 75],
            'confidence' => 0.94,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/ai-reconciliation/{$job->id}")
            ->assertOk()
            ->assertSee('Phát hiện lệch thời gian nghiêm trọng.')
            ->assertSee('Giờ cuối ca lệch trên 60 phút')
            ->assertSee('75')
            ->assertSee('Lịch sử thông báo')
            ->assertSee('PENDING');
    }

    public function test_user_can_queue_one_openclaw_command_per_job(): void
    {
        $user = User::factory()->create();
        $job = $this->createJob('VT-XL1404', '2026-08-23', 'COMPLETED');
        $payload = [
            'action' => 'EXPLAIN_RESULT',
            'instruction' => 'Giải thích kết quả đối soát hiện tại.',
        ];

        $this->actingAs($user)
            ->post("/ai-reconciliation/{$job->id}/commands", $payload)
            ->assertRedirect(route('ai-reconciliation.show', $job));

        $this->assertDatabaseHas('open_claw_commands', [
            'ai_reconciliation_job_id' => $job->id,
            'user_id' => $user->id,
            'status' => 'PENDING',
        ]);

        $this->actingAs($user)
            ->post("/ai-reconciliation/{$job->id}/commands", $payload)
            ->assertSessionHasErrors('instruction');
        $this->assertDatabaseCount('open_claw_commands', 1);
    }

    public function test_worker_claims_and_completes_command(): void
    {
        $job = $this->createJob('VT-XL1405', '2026-08-23', 'COMPLETED');
        $command = $job->commands()->create([
            'user_id' => User::factory()->create()->id,
            'action' => 'CHECK_EVIDENCE',
            'instruction' => 'Kiểm tra bằng chứng đang có.',
            'status' => 'PENDING',
        ]);

        $this->withToken('test-openclaw-token')
            ->postJson('/api/openclaw/v1/commands/claim', [
                'worker_id' => 'openclaw-home-1',
                'limit' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('commands.0.id', $command->id)
            ->assertJsonPath('commands.0.reconciliation_job.machine.asset_code', 'VT-XL1405');

        $this->withToken('test-openclaw-token')
            ->postJson("/api/openclaw/v1/commands/{$command->id}/complete", [
                'worker_id' => 'openclaw-home-1',
                'summary' => 'Bằng chứng đầy đủ.',
                'result' => ['suggested_actions' => []],
            ])
            ->assertOk()
            ->assertJsonPath('command.status', 'COMPLETED');

        $this->assertDatabaseHas('open_claw_commands', [
            'id' => $command->id,
            'status' => 'COMPLETED',
            'result_summary' => 'Bằng chứng đầy đủ.',
        ]);
    }

    private function createJob(string $assetCode, string $workDate, string $status): AiReconciliationJob
    {
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
}
