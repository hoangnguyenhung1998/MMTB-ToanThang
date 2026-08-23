<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'collector.token' => 'rate-limit-collector-token',
            'collector.rate_limit_per_minute' => 1,
            'ocr.worker_token' => 'rate-limit-ocr-token',
            'ocr.rate_limit_per_minute' => 1,
            'openclaw.reconciliation_token' => 'rate-limit-openclaw-token',
            'openclaw.rate_limit_per_minute' => 1,
        ]);
    }

    public function test_worker_services_do_not_share_a_rate_limit_bucket(): void
    {
        $this->withToken('rate-limit-collector-token')
            ->postJson('/api/collector/v1/zalo/messages')
            ->assertUnprocessable();

        $this->withToken('rate-limit-collector-token')
            ->postJson('/api/collector/v1/zalo/messages')
            ->assertTooManyRequests();

        $this->withToken('rate-limit-ocr-token')
            ->getJson('/api/ocr/v1/machines')
            ->assertOk();

        $this->withToken('rate-limit-openclaw-token')
            ->postJson('/api/openclaw/v1/reconciliation/jobs/claim', [
                'worker_id' => 'rate-limit-test-worker',
                'work_date' => '2026-08-23',
            ])
            ->assertNoContent();
    }
}
