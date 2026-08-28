<?php

namespace Tests\Feature;

use App\Models\MachineIntakeCase;
use App\Models\MachineIntakeEmailReply;
use App\Models\User;
use App\Services\MachineIntakeEmailReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MachineIntakeEmailReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gmail_intake.worker_token' => 'gmail-test-token', 'telegram.enabled' => true, 'telegram.bot_token' => 'token', 'telegram.chat_id' => '1']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Storage::fake('public');
    }

    public function test_reply_matches_waiting_case_by_reference_and_is_idempotent(): void
    {
        $case = $this->waitingCase('TN-2026-000501', 'FRAME501', 'ENGINE501', 'gmail-thread-501');
        $payload = ['gmail_message_id' => 'gmail-message-1', 'gmail_thread_id' => 'another-thread', 'sender' => 'bch@example.com', 'subject' => 'Re: '.$case->reference, 'body_text' => 'Mã cấp: VT-XL1501', 'candidate_asset_code' => 'VT-XL1501', 'confidence' => .99];

        $this->withToken('gmail-test-token')->postJson('/api/gmail-intake/v1/replies', $payload)->assertOk()->assertJsonPath('reply.status', 'PENDING')->assertJsonPath('reply.case_reference', $case->reference);
        $this->withToken('gmail-test-token')->postJson('/api/gmail-intake/v1/replies', $payload)->assertOk();

        $this->assertSame(1, MachineIntakeEmailReply::count());
        $this->assertDatabaseHas('machine_intake_events', ['machine_intake_case_id' => $case->id, 'event' => 'intake.gmail_reply_received']);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'VT-XL1501') && str_contains($request['text'], $case->reference));
    }

    public function test_reply_prefers_exact_gmail_thread_and_keeps_image_evidence(): void
    {
        $case = $this->waitingCase('TN-2026-000502', 'FRAME502', 'ENGINE502', 'gmail-thread-502');
        $reply = app(MachineIntakeEmailReplyService::class)->ingest([
            'gmail_message_id' => 'gmail-message-2', 'gmail_thread_id' => 'gmail-thread-502', 'sender' => 'bch@example.com',
            'subject' => 'Đã cấp mã', 'candidate_asset_code' => 'T-XX2502', 'confidence' => .94,
            'evidence_name' => 'ma.jpg', 'evidence_base64' => base64_encode('image-content'),
        ]);

        $this->assertSame($case->id, $reply->machine_intake_case_id);
        $this->assertSame('GMAIL_THREAD', $reply->match_method);
        Storage::disk('public')->assertExists($reply->evidence_path);
    }

    public function test_user_confirmation_creates_only_matched_machine_waiting_handover(): void
    {
        $case = $this->waitingCase('TN-2026-000503', 'FRAME503', 'ENGINE503', 'gmail-thread-503');
        $reply = app(MachineIntakeEmailReplyService::class)->ingest(['gmail_message_id' => 'gmail-message-3', 'gmail_thread_id' => 'gmail-thread-503', 'sender' => 'bch@example.com', 'candidate_asset_code' => 'VT-XL1503', 'confidence' => .98]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('machine-intakes.email-replies.confirm', [$case, $reply]))->assertRedirect();

        $this->assertSame('CONFIRMED', $reply->fresh()->status);
        $this->assertSame('WAIT_HANDOVER', $case->fresh()->status);
        $this->assertDatabaseHas('machines', ['asset_code' => 'VT-XL1503', 'chassis_no' => 'FRAME503', 'status' => 'WAIT_HANDOVER']);
    }

    public function test_unmatched_email_never_creates_machine(): void
    {
        $reply = app(MachineIntakeEmailReplyService::class)->ingest(['gmail_message_id' => 'gmail-message-4', 'subject' => 'Không có tham chiếu', 'candidate_asset_code' => 'T-XL9999', 'confidence' => .99]);
        $this->assertSame('UNMATCHED', $reply->status);
        $this->assertNull($reply->machine_intake_case_id);
        $this->assertDatabaseCount('machines', 0);
    }

    public function test_api_requires_dedicated_worker_token(): void
    {
        $this->postJson('/api/gmail-intake/v1/replies', ['gmail_message_id' => 'x'])->assertUnauthorized();
    }

    private function waitingCase(string $reference, string $chassis, string $engine, string $thread): MachineIntakeCase
    {
        return MachineIntakeCase::create(['reference' => $reference, 'status' => 'WAIT_ASSET_CODE', 'company' => 'VINALPHA', 'chassis_no' => $chassis, 'engine_no' => $engine, 'machine_type' => 'Máy xúc bánh xích 200', 'manufacture_year' => 2020, 'email_thread_id' => $thread, 'email_sent_at' => now()]);
    }
}
