<?php

namespace Tests\Feature;

use App\Models\AutomationIncident;
use App\Models\AutomationNode;
use App\Models\AutomationService;
use App\Models\User;
use App\Services\AutomationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_requires_a_registered_node_token(): void
    {
        $this->postJson('/api/automation/v1/heartbeat', ['services' => []])->assertUnauthorized();
    }

    public function test_heartbeat_updates_service_snapshot_without_growing_history(): void
    {
        $this->node('secret-token');
        $payload = $this->payload('HEALTHY');
        $this->withToken('secret-token')->postJson('/api/automation/v1/heartbeat', $payload)
            ->assertOk()->assertJsonPath('services.0.status', 'HEALTHY');
        $this->withToken('secret-token')->postJson('/api/automation/v1/heartbeat', $payload)->assertOk();

        $this->assertDatabaseCount('automation_services', 1);
        $this->assertDatabaseCount('automation_incidents', 0);
        $this->assertDatabaseHas('automation_services', ['service_key' => 'ocr-worker', 'current_job' => 'ocr-job-26', 'queue_depth' => 2]);
    }

    public function test_three_errors_degrade_service_and_recovery_resolves_incident(): void
    {
        $this->node('secret-token');
        $payload = $this->payload('HEALTHY');
        $payload['services'][0]['consecutive_errors'] = 3;
        $payload['services'][0]['error_message'] = 'Laravel API timeout.';
        $this->withToken('secret-token')->postJson('/api/automation/v1/heartbeat', $payload)
            ->assertOk()->assertJsonPath('services.0.status', 'DEGRADED');
        $this->assertDatabaseHas('automation_incidents', ['type' => 'DEGRADED', 'status' => 'OPEN']);

        $this->withToken('secret-token')->postJson('/api/automation/v1/heartbeat', $this->payload('HEALTHY'))
            ->assertOk()->assertJsonPath('services.0.status', 'HEALTHY');
        $this->assertDatabaseHas('automation_incidents', ['type' => 'DEGRADED', 'status' => 'RESOLVED']);
    }

    public function test_stale_heartbeat_becomes_offline_and_is_recorded_once(): void
    {
        $service = AutomationService::query()->create([
            'automation_node_id' => $this->node('secret-token')->id,
            'service_key' => 'openclaw', 'name' => 'OpenClaw Gateway',
            'service_type' => 'OPENCLAW_GATEWAY', 'reported_status' => 'HEALTHY',
            'last_heartbeat_at' => now()->subMinutes(6),
        ]);
        $health = app(AutomationHealthService::class);
        $this->assertSame('OFFLINE', $health->statusFor($service));
        $health->evaluateAll();
        $health->evaluateAll();
        $this->assertSame(1, AutomationIncident::query()->where('type', 'OFFLINE')->count());
    }

    public function test_authenticated_user_can_view_dashboard(): void
    {
        AutomationService::query()->create([
            'automation_node_id' => $this->node('secret-token')->id,
            'service_key' => 'journal-worker', 'name' => 'Journal Worker',
            'service_type' => 'JOURNAL_WORKER', 'reported_status' => 'HEALTHY',
            'last_heartbeat_at' => now(),
        ]);
        $this->actingAs(User::factory()->create())->get('/automation-health')
            ->assertOk()->assertSee('Giám sát tiến trình tự động')
            ->assertSee('Laptop 24/24')->assertSee('Journal Worker')->assertSee('Hoạt động');
    }

    private function node(string $token): AutomationNode
    {
        return AutomationNode::query()->create([
            'node_key' => 'laptop-24-7', 'name' => 'Laptop 24/24', 'location' => 'Nhà',
            'token_hash' => hash('sha256', $token),
        ]);
    }

    private function payload(string $status): array
    {
        return ['agent_version' => '1.0.0', 'services' => [[
            'service_key' => 'ocr-worker', 'name' => 'OCR Worker', 'service_type' => 'OCR_WORKER',
            'status' => $status, 'version' => '0.1.0', 'current_job' => 'ocr-job-26',
            'queue_depth' => 2, 'consecutive_errors' => 0, 'last_success_at' => now()->toIso8601String(),
        ]]];
    }
}
