<?php

namespace Tests\Feature\Api;

use App\Models\ZaloAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ZaloIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'collector.token' => 'test-collector-token',
            'collector.disk' => 'local',
            'collector.directory' => 'zalo',
        ]);
        Storage::fake('local');
    }

    public function test_collector_must_authenticate(): void
    {
        $this->postJson('/api/collector/v1/zalo/messages')
            ->assertUnauthorized();
    }

    public function test_it_stores_a_new_zalo_image(): void
    {
        $contents = 'first-image-bytes';
        $response = $this->postImage('message-1', $contents);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'STORED')
            ->assertJsonPath('data.sha256', hash('sha256', $contents));

        $attachment = ZaloAttachment::query()->sole();
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertDatabaseHas('zalo_messages', [
            'group_id' => 'group-1',
            'message_id' => 'message-1',
            'status' => 'STORED',
        ]);
        $this->assertDatabaseHas('ocr_jobs', [
            'zalo_attachment_id' => $attachment->id,
            'status' => 'PENDING',
        ]);
    }

    public function test_replaying_the_same_message_is_idempotent(): void
    {
        $this->postImage('message-1', 'same-bytes')->assertCreated();
        $this->postImage('message-1', 'same-bytes')->assertOk();

        $this->assertDatabaseCount('zalo_messages', 1);
        $this->assertDatabaseCount('zalo_attachments', 1);
    }

    public function test_same_file_in_another_message_is_recorded_as_duplicate(): void
    {
        $first = $this->postImage('message-1', 'same-bytes')->assertCreated();
        $second = $this->postImage('message-2', 'same-bytes')
            ->assertCreated()
            ->assertJsonPath('data.status', 'DUPLICATE');

        $second->assertJsonPath('data.duplicate_of_attachment_id', $first->json('data.attachment_id'));
        $this->assertDatabaseCount('zalo_messages', 2);
        $this->assertDatabaseCount('zalo_attachments', 2);
        $this->assertDatabaseCount('ocr_jobs', 1);
    }

    public function test_it_rejects_a_hash_mismatch(): void
    {
        $payload = $this->payload('message-1', 'actual-bytes');
        $payload['sha256'] = str_repeat('a', 64);

        $this->withToken('test-collector-token')
            ->withHeader('Accept', 'application/json')
            ->post('/api/collector/v1/zalo/messages', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sha256');
    }

    private function postImage(string $messageId, string $contents)
    {
        return $this->withToken('test-collector-token')
            ->post('/api/collector/v1/zalo/messages', $this->payload($messageId, $contents));
    }

    private function payload(string $messageId, string $contents): array
    {
        return [
            'group_id' => 'group-1',
            'message_id' => $messageId,
            'sender_id' => 'sender-1',
            'sender_name' => 'T-XL0354 | Le The Vy',
            'sent_at' => '2026-08-19T09:48:40+00:00',
            'attachment_index' => 0,
            'sha256' => hash('sha256', $contents),
            'raw_payload' => json_encode(['msgType' => 'chat.photo'], JSON_THROW_ON_ERROR),
            'file' => UploadedFile::fake()->createWithContent('timemark.jpg', $contents),
        ];
    }
}
