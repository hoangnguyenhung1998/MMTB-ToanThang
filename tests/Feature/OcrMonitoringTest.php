<?php

namespace Tests\Feature;

use App\Models\OcrJob;
use App\Models\OcrProcessingRun;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\OcrCapacityAlertRecorder;
use App\Services\AutomationHealthAlertDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcrMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authenticated_user_sees_realtime_ocr_metrics_and_safe_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'Asia/Ho_Chi_Minh')->utc());
        $user = User::factory()->create();
        $job = $this->job('COMPLETED', now()->subSeconds(12), now());
        OcrProcessingRun::query()->create([
            'ocr_job_id' => $job->id,
            'worker_id' => 'rapid-ocr-home-1',
            'stage' => 'TIMEMARK',
            'attempt' => 1,
            'status' => 'COMPLETED',
            'started_at' => now()->subSeconds(12),
            'finished_at' => now(),
            'duration_ms' => 12000,
        ]);

        $this->actingAs($user)->get('/ocr-monitoring')->assertOk()
            ->assertSee('Giám sát OCR realtime')
            ->assertSee('Ảnh nhận hôm nay')
            ->assertSee('Tự cập nhật mỗi 5 giây')
            ->assertSee('Giám sát OCR')
            ->assertSee('Ảnh & OCR', false)
            ->assertSee('sidebarCollapse')
            ->assertDontSee('cookie')
            ->assertDontSee('imei');

        $this->actingAs($user)->getJson('/ocr-monitoring/status')->assertOk()
            ->assertJsonPath('summary.received_today', 1)
            ->assertJsonPath('summary.processed_today', 1)
            ->assertJsonPath('summary.runtime.average_ms', 12000)
            ->assertJsonPath('runs.0.job_id', $job->id)
            ->assertJsonPath('runs.0.duration_ms', 12000)
            ->assertJsonPath('runs.0.sender_name', 'Lái máy thử nghiệm');
    }

    public function test_capacity_alert_is_recorded_when_old_backlog_has_stalled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 23:30:00', 'Asia/Ho_Chi_Minh')->utc());
        $user = User::factory()->create();
        config(['telegram.enabled' => true, 'telegram.bot_token' => 'bot-token', 'telegram.chat_id' => '123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $job = $this->job('PENDING');
        $job->forceFill(['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)])->save();

        app(OcrCapacityAlertRecorder::class)->evaluate();

        $this->assertDatabaseHas('automation_health_alerts', [
            'event_key' => 'ocr-capacity:2026-09-05:danger',
            'kind' => 'OCR_CAPACITY',
            'status' => 'PENDING',
        ]);
        $this->assertSame(
            'ocr_capacity',
            data_get($user->notifications()->first()?->data, 'category'),
        );

        app(AutomationHealthAlertDispatcher::class)->dispatch();
        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'OCR có nguy cơ không kịp ngày')
            && str_contains((string) $request['text'], 'Ảnh còn chờ'));
    }

    private function job(string $status, $createdAt = null, $processedAt = null): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'group-1',
            'message_id' => 'message-'.uniqid(),
            'sender_id' => 'sender-1',
            'sender_name' => 'Lái máy thử nghiệm',
            'sent_at' => $createdAt ?? now(),
            'received_at' => $createdAt ?? now(),
            'status' => 'STORED',
        ]);
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'storage_disk' => 'local',
            'storage_path' => 'zalo/test.jpg',
            'sha256' => hash('sha256', uniqid('', true)),
            'mime_type' => 'image/jpeg',
            'byte_size' => 100,
            'status' => 'STORED',
        ]);

        $job = OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'status' => $status,
            'document_type' => 'DAILY_TIMEMARK',
            'processed_at' => $processedAt,
        ]);
        if ($createdAt) {
            $job->forceFill(['created_at' => $createdAt, 'updated_at' => $processedAt ?? $createdAt])->save();
        }

        return $job;
    }
}
