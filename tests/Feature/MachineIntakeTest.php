<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\MachineIntakeCase;
use App\Models\User;
use App\Services\MachineIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MachineIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private MachineIntakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->service = app(MachineIntakeService::class);
        config(['telegram.enabled' => true, 'telegram.bot_token' => 'test-token', 'telegram.chat_id' => '123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_multiple_cases_wait_independently_and_first_code_creates_only_its_machine(): void
    {
        $first = $this->waitingCase('FRAME001', 'ENGINE001');
        $second = $this->waitingCase('FRAME002', 'ENGINE002');

        $result = $this->service->assignAssetCode($second, ['asset_code' => 'VT-XX0002', 'asset_code_source' => 'EMAIL_REPLY'], $this->user);

        $this->assertSame('WAIT_HANDOVER', $result->status);
        $this->assertSame('WAIT_ASSET_CODE', $first->fresh()->status);
        $this->assertDatabaseHas('machines', ['asset_code' => 'VT-XX0002', 'chassis_no' => 'FRAME002', 'status' => 'WAIT_HANDOVER']);
        $this->assertDatabaseMissing('machines', ['chassis_no' => 'FRAME001']);
    }

    public function test_email_and_external_sources_share_assignment_flow_and_keep_evidence(): void
    {
        Storage::fake('public');
        $case = $this->waitingCase('FRAME003', 'ENGINE003');
        $result = $this->service->assignAssetCode($case, [
            'asset_code' => 'vt xx 0003', 'asset_code_source' => 'ZALO_BCH', 'asset_code_source_note' => 'BCH gửi ảnh mã',
        ], $this->user, UploadedFile::fake()->image('ma-may.jpg'));

        $this->assertSame('VTXX0003', $result->asset_code);
        $this->assertSame('ZALO_BCH', $result->asset_code_source);
        Storage::disk('public')->assertExists($result->code_evidence_path);
        $this->assertDatabaseHas('machine_intake_events', ['machine_intake_case_id' => $case->id, 'event' => 'intake.asset_code_assigned']);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'VTXX0003') && str_contains($request['text'], $case->reference));
    }

    public function test_duplicate_code_and_chassis_are_blocked(): void
    {
        $first = $this->waitingCase('FRAME004', 'ENGINE004');
        $this->service->assignAssetCode($first, ['asset_code' => 'VT-XX0004', 'asset_code_source' => 'EMAIL_REPLY'], $this->user);
        $second = $this->waitingCase('FRAME005', 'ENGINE005');

        $this->expectException(BusinessRuleException::class);
        $this->service->assignAssetCode($second, ['asset_code' => 'VT-XX0004', 'asset_code_source' => 'OTHER'], $this->user);
    }

    public function test_source_document_keeps_relative_path_hash_and_immutable_history(): void
    {
        Storage::fake('public');
        $case = $this->service->createDraft(['document_type' => 'REGISTRATION_CERTIFICATE'], [UploadedFile::fake()->image('dang-kiem.jpg')], $this->user);

        $document = $case->documents()->firstOrFail();
        $this->assertStringNotContainsString(storage_path(), $document->storage_path);
        $this->assertSame(64, strlen($document->sha256));
        Storage::disk('public')->assertExists($document->storage_path);
        $this->assertDatabaseHas('machine_intake_events', ['machine_intake_case_id' => $case->id, 'event' => 'intake.created']);
    }

    private function waitingCase(string $chassis, string $engine): MachineIntakeCase
    {
        $case = $this->service->createDraft([], [], $this->user);
        $case = $this->service->confirm($case, [
            'company' => 'VINALPHA', 'chassis_no' => $chassis, 'engine_no' => $engine,
            'machine_type' => 'Máy xúc đào', 'manufacture_year' => 2020,
        ], $this->user);
        return $this->service->markEmailSent($case, ['email_thread_id' => 'thread-'.$case->id], $this->user);
    }
}
