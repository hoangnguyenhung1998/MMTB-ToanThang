<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Machine;
use App\Models\MachineIntakeCase;
use App\Models\MachineIntakeEmailReply;
use App\Models\User;
use App\Services\MachineIntakeBchService;
use App\Services\MachineIntakeDuplicateService;
use App\Services\MachineIntakeEmailReplyService;
use App\Services\MachineIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MachineIntakeDuplicateGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private MachineIntakeService $intakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->intakes = app(MachineIntakeService::class);
        Storage::fake('public');
        Mail::fake();
        Http::fake();
    }

    public function test_confirmation_is_blocked_when_chassis_already_belongs_to_machine(): void
    {
        $this->machine('VT-XL0100', 'DUPLICATE-FRAME-100');
        $case = $this->intakes->createDraft([], [], $this->user);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('VT-XL0100');
        $this->confirm($case, 'DUPLICATE-FRAME-100');
    }

    public function test_confirmation_is_blocked_when_another_open_intake_has_same_chassis(): void
    {
        $first = $this->intakes->createDraft([], [], $this->user);
        $this->confirm($first, 'DUPLICATE-FRAME-101');
        $second = $this->intakes->createDraft([], [], $this->user);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage($first->reference);
        $this->confirm($second, 'DUPLICATE-FRAME-101');
    }

    public function test_duplicate_created_after_confirmation_is_blocked_before_excel_generation(): void
    {
        $case = $this->intakes->createDraft([], [], $this->user);
        $this->confirm($case, 'DUPLICATE-FRAME-102');
        $this->machine('VT-XL0102', 'DUPLICATE-FRAME-102');

        $this->expectException(BusinessRuleException::class);
        app(MachineIntakeBchService::class)->prepare($case->fresh(), $this->bchData());
    }

    public function test_duplicate_created_after_excel_is_blocked_before_email_send(): void
    {
        $this->configureTestSender();
        $case = $this->intakes->createDraft([], [], $this->user);
        $this->confirm($case, 'DUPLICATE-FRAME-103');
        $bch = app(MachineIntakeBchService::class);
        $bch->prepare($case->fresh(), $this->bchData());
        $this->machine('VT-XL0103', 'DUPLICATE-FRAME-103');

        try {
            $bch->send($case->fresh(), $this->user);
            $this->fail('Duplicate chassis should block email delivery.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('VT-XL0103', $exception->getMessage());
        }

        Mail::assertNothingSent();
        $this->assertSame('CONFIRMED', $case->fresh()->status);
    }

    public function test_duplicate_case_can_be_closed_and_pending_reply_is_rejected(): void
    {
        $machine = $this->machine('VT-XL0104', 'DUPLICATE-FRAME-104');
        $case = MachineIntakeCase::create([
            'reference' => 'TN-2026-000104', 'status' => 'WAIT_ASSET_CODE', 'company' => 'VINALPHA',
            'chassis_no' => 'DUPLICATE-FRAME-104', 'engine_no' => 'ENGINE104',
            'machine_type' => 'Máy xúc bánh lốp', 'manufacture_year' => 2020,
        ]);
        $reply = MachineIntakeEmailReply::create([
            'machine_intake_case_id' => $case->id, 'gmail_message_id' => 'duplicate-message-104',
            'candidate_asset_code' => 'VT-XL9104', 'status' => 'PENDING',
        ]);

        $result = app(MachineIntakeDuplicateService::class)->closeAsDuplicate($case, $this->user);

        $this->assertSame('DUPLICATE', $result->status);
        $this->assertSame($machine->id, $result->duplicate_machine_id);
        $this->assertNotNull($result->closed_at);
        $this->assertSame('REJECTED_DUPLICATE', $reply->fresh()->status);
        $this->assertDatabaseHas('machine_intake_events', [
            'machine_intake_case_id' => $case->id,
            'event' => 'intake.closed_as_duplicate',
        ]);
    }

    public function test_new_gmail_reply_is_rejected_when_legacy_waiting_case_is_duplicate(): void
    {
        $case = MachineIntakeCase::create([
            'reference' => 'TN-2026-000105', 'status' => 'WAIT_ASSET_CODE', 'company' => 'VINALPHA',
            'chassis_no' => 'DUPLICATE-FRAME-105', 'engine_no' => 'ENGINE105',
            'machine_type' => 'Máy xúc bánh lốp', 'manufacture_year' => 2020,
            'email_thread_id' => 'duplicate-thread-105',
        ]);
        $this->machine('VT-XL0105', 'DUPLICATE-FRAME-105');

        $reply = app(MachineIntakeEmailReplyService::class)->ingest([
            'gmail_message_id' => 'duplicate-message-105',
            'gmail_thread_id' => 'duplicate-thread-105',
            'candidate_asset_code' => 'VT-XL9105',
            'confidence' => .99,
        ]);

        $this->assertSame($case->id, $reply->machine_intake_case_id);
        $this->assertSame('REJECTED_DUPLICATE', $reply->status);
        Http::assertNothingSent();
    }

    private function confirm(MachineIntakeCase $case, string $chassis): MachineIntakeCase
    {
        return $this->intakes->confirm($case, [
            'company' => 'VINALPHA',
            'chassis_no' => $chassis,
            'engine_no' => 'ENGINE-'.$case->id,
            'machine_type' => 'Máy xúc bánh lốp',
            'manufacture_year' => 2020,
        ], $this->user);
    }

    private function machine(string $assetCode, string $chassis): Machine
    {
        return Machine::create([
            'asset_code' => $assetCode,
            'company' => 'VINALPHA',
            'chassis_no' => $chassis,
            'engine_no' => 'EXISTING-'.$assetCode,
            'status' => 'ACTIVE',
        ]);
    }

    private function bchData(): array
    {
        return [
            'sender_profile' => 'test',
            'to' => 'bch@example.com',
            'subject' => 'Đề nghị cấp mã',
            'body' => 'Nội dung kiểm thử',
        ];
    }

    private function configureTestSender(): void
    {
        config([
            'machine_intake_mail.senders.test.address' => 'sender@example.com',
            'mail.mailers.machine_intake_test.username' => 'sender@example.com',
            'mail.mailers.machine_intake_test.password' => 'app-password',
        ]);
    }
}
