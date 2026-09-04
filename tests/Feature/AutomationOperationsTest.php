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

    public function test_user_can_queue_safe_zalo_account_switch_and_agent_receives_payload(): void
    {
        [$node, $service] = $this->service('ZALO_COLLECTOR'); $user = User::factory()->create();
        $this->actingAs($user)->post("/automation-health/services/{$service->id}/commands", [
            'action' => 'ZALO_ACCOUNT_SWITCH', 'account_id' => 'zalo-company',
        ])->assertRedirect();

        $claim = $this->withToken('node-token')->postJson('/api/automation/v1/commands/claim', [
            'agent_id' => 'laptop-home',
        ])->assertOk();

        $claim->assertJsonPath('commands.0.action', 'ZALO_ACCOUNT_SWITCH')
            ->assertJsonPath('commands.0.payload.account_id', 'zalo-company');
        $this->assertDatabaseHas('automation_operational_commands', [
            'automation_node_id' => $node->id, 'action' => 'ZALO_ACCOUNT_SWITCH', 'status' => 'PROCESSING',
        ]);
    }

    public function test_zalo_switch_rejects_unsafe_account_id_and_non_zalo_service(): void
    {
        [, $zalo] = $this->service('ZALO_COLLECTOR'); $user = User::factory()->create();
        $this->actingAs($user)->from('/automation-health')->post("/automation-health/services/{$zalo->id}/commands", [
            'action' => 'ZALO_ACCOUNT_SWITCH', 'account_id' => '../credentials',
        ])->assertRedirect('/automation-health')->assertSessionHasErrors('account_id');

        [, $ocr] = $this->service();
        $this->actingAs($user)->from('/automation-health')->post("/automation-health/services/{$ocr->id}/commands", [
            'action' => 'ZALO_ACCOUNT_SWITCH', 'account_id' => 'zalo-company',
        ])->assertRedirect('/automation-health')->assertSessionHasErrors('action');
    }

    public function test_dashboard_shows_safe_zalo_profiles_from_heartbeat_metrics(): void
    {
        [, $service] = $this->service('ZALO_COLLECTOR'); $user = User::factory()->create();
        $service->update(['metrics' => [
            'active_account_id' => 'zalo-test', 'active_account_name' => 'Zalo kiểm thử',
            'zalo_accounts' => [
                ['id' => 'zalo-test', 'name' => 'Zalo kiểm thử', 'group_count' => 1, 'has_session' => true, 'ready' => true],
                ['id' => 'zalo-company', 'name' => 'Zalo công ty', 'group_count' => 2, 'has_session' => false, 'ready' => false],
            ],
        ]]);

        $this->actingAs($user)->get('/automation-health')->assertOk()
            ->assertSee('Zalo kiểm thử')->assertSee('Zalo công ty')->assertSee('chưa sẵn sàng');
    }

    public function test_successful_zalo_switch_sends_telegram_without_session_data(): void
    {
        config(['telegram.enabled' => true, 'telegram.bot_token' => 'bot-token', 'telegram.chat_id' => '123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        [, $service] = $this->service('ZALO_COLLECTOR'); $user = User::factory()->create();
        $this->actingAs($user)->post("/automation-health/services/{$service->id}/commands", [
            'action' => 'ZALO_ACCOUNT_SWITCH', 'account_id' => 'zalo-company',
        ]);
        $claim = $this->withToken('node-token')->postJson('/api/automation/v1/commands/claim', ['agent_id' => 'laptop-home']);
        $id = $claim->json('commands.0.id');
        $this->withToken('node-token')->postJson("/api/automation/v1/commands/{$id}/complete", [
            'result' => ['message' => 'Switched'],
        ])->assertOk();

        Http::assertSent(function ($request): bool {
            $text = (string) $request['text'];
            return str_contains($text, 'zalo-company')
                && ! str_contains($text, 'cookie')
                && ! str_contains($text, 'imei');
        });
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

    private function service(string $type = 'OCR_WORKER'): array
    {
        $node = AutomationNode::query()->firstOrCreate(
            ['node_key' => 'laptop-24-7'],
            ['name' => 'Laptop 24/24', 'token_hash' => hash('sha256', 'node-token')],
        );
        $service = AutomationService::query()->create([
            'automation_node_id' => $node->id,
            'service_key' => $type === 'ZALO_COLLECTOR' ? 'zalo-collector' : 'ocr-worker',
            'name' => $type === 'ZALO_COLLECTOR' ? 'Zalo Collector' : 'OCR Worker',
            'service_type' => $type, 'reported_status' => 'HEALTHY', 'last_heartbeat_at' => now(),
            'metrics' => $type === 'ZALO_COLLECTOR' ? ['zalo_accounts' => [[
                'id' => 'zalo-company', 'name' => 'Zalo công ty', 'group_count' => 1,
                'has_session' => true, 'ready' => true,
            ]]] : null,
        ]);
        return [$node, $service];
    }
}
