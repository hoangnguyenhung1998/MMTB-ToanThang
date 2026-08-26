<?php

namespace Tests\Feature;

use App\Models\AutomationNode;
use App\Models\AutomationService;
use App\Models\User;
use App\Services\AutomationHealthAlertDispatcher;
use App\Services\AutomationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_queues_allowlisted_command_and_agent_completes_it(): void
    {
        [$node, $service] = $this->service(); $user = User::factory()->create();
        $this->actingAs($user)->post("/automation-health/services/{$service->id}/commands", ['action' => 'RESTART'])->assertRedirect();
        $claim = $this->withToken('node-token')->postJson('/api/automation/v1/commands/claim', ['agent_id' => 'laptop-home'])->assertOk();
        $id = $claim->json('commands.0.id');
        $this->withToken('node-token')->postJson("/api/automation/v1/commands/{$id}/complete", ['result' => ['message' => 'Restarted']])->assertOk();
        $this->assertDatabaseHas('automation_operational_commands', ['id' => $id, 'status' => 'COMPLETED']);
    }

    public function test_job_over_threshold_creates_hung_incident_and_alert(): void
    {
        [, $service] = $this->service();
        $service->update(['current_job' => 'job-99', 'current_job_started_at' => now()->subMinutes(31)]);
        app(AutomationHealthService::class)->evaluateAll();
        $this->assertDatabaseHas('automation_incidents', ['automation_service_id' => $service->id, 'type' => 'HUNG', 'status' => 'OPEN']);
        $this->assertDatabaseHas('automation_health_alerts', ['kind' => 'OPENED', 'status' => 'PENDING']);
    }

    public function test_telegram_alert_is_deduplicated_and_recovery_is_sent(): void
    {
        config(['telegram.enabled' => true, 'telegram.bot_token' => 'bot-token', 'telegram.chat_id' => '123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        [, $service] = $this->service(); $health = app(AutomationHealthService::class);
        $health->synchronizeIncident($service, 'OFFLINE');
        $health->synchronizeIncident($service, 'OFFLINE');
        $first = app(AutomationHealthAlertDispatcher::class)->dispatch();
        $health->synchronizeIncident($service, 'HEALTHY');
        $second = app(AutomationHealthAlertDispatcher::class)->dispatch();
        $this->assertSame(1, $first['sent']); $this->assertSame(1, $second['sent']);
        $this->assertDatabaseCount('automation_health_alerts', 2);
        Http::assertSentCount(2);
    }

    private function service(): array
    {
        $node = AutomationNode::query()->create(['node_key' => 'laptop-24-7', 'name' => 'Laptop 24/24', 'token_hash' => hash('sha256', 'node-token')]);
        $service = AutomationService::query()->create([
            'automation_node_id' => $node->id, 'service_key' => 'ocr-worker', 'name' => 'OCR Worker',
            'service_type' => 'OCR_WORKER', 'reported_status' => 'HEALTHY', 'last_heartbeat_at' => now(),
        ]);
        return [$node, $service];
    }
}
