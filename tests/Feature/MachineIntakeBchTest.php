<?php

namespace Tests\Feature;

use App\Mail\MachineIntakeBchMail;
use App\Models\MachineIntakeCase;
use App\Models\MachineIntakeEvent;
use App\Models\User;
use App\Services\MachineIntakeBchService;
use App\Services\MachineSpecificationNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MachineIntakeBchTest extends TestCase
{
    use RefreshDatabase;

    public function test_dx60_wheel_excavator_normalizes_to_capacity_55(): void
    {
        $result = app(MachineSpecificationNormalizer::class)->normalize([
            'machine_type' => 'Máy đào bánh lốp',
            'model_name' => 'Doosan DX60',
        ]);

        $this->assertSame('Máy xúc bánh lốp', $result['machine_type']);
        $this->assertSame(55, $result['capacity_class']);
        $this->assertSame('Doosan', $result['brand']);
    }

    public function test_vehicle_identity_combines_brand_and_plate(): void
    {
        $normalizer = app(MachineSpecificationNormalizer::class);
        $result = $normalizer->normalize([
            'machine_type' => 'Xe ben 3 chân',
            'brand' => 'HOWO',
            'plate_no' => '15K-01234',
        ]);

        $this->assertSame('Xe ô tô 3 chân', $normalizer->displayType($result));
        $this->assertSame('HOWO · 15K-01234', $normalizer->displayIdentity($result));
    }

    public function test_confirmed_case_generates_excel_and_sends_with_selected_profile(): void
    {
        $this->configureSenders();
        Storage::fake('public');
        Mail::fake();
        $user = User::factory()->create();
        $case = $this->confirmedCase($user);

        $service = app(MachineIntakeBchService::class);
        $service->prepare($case, [
            'sender_profile' => 'test',
            'to' => 'bch@example.com',
            'subject' => 'Cấp mã FRAME99',
            'body' => 'Đề nghị cấp mã',
        ]);

        Storage::disk('public')->assertExists($case->fresh()->bch_package_path);
        $this->assertSame('test', $case->fresh()->bch_sender_profile);
        Mail::assertNothingSent();

        $service->send($case->fresh(), $user);

        Mail::assertSent(MachineIntakeBchMail::class, function (MachineIntakeBchMail $mail) {
            $envelope = $mail->envelope();

            return $envelope->from->address === 'test.sender@gmail.com'
                && $envelope->replyTo[0]->address === 'watched.inbox@gmail.com';
        });
        $this->assertSame('WAIT_ASSET_CODE', $case->fresh()->status);
        $this->assertDatabaseHas('machine_intake_events', [
            'machine_intake_case_id' => $case->id,
            'event' => 'intake.bch_email_sent',
        ]);
        $event = MachineIntakeEvent::where('machine_intake_case_id', $case->id)
            ->where('event', 'intake.bch_email_sent')->firstOrFail();
        $this->assertSame('test', $event->properties['sender_profile']);
        $this->assertSame('test.sender@gmail.com', $event->properties['sender_address']);
    }

    public function test_company_profile_can_be_selected_independently(): void
    {
        $this->configureSenders();
        Storage::fake('public');
        Mail::fake();
        $user = User::factory()->create();
        $case = $this->confirmedCase($user);

        $service = app(MachineIntakeBchService::class);
        $service->prepare($case, [
            'sender_profile' => 'company',
            'to' => 'bch@example.com',
            'subject' => 'Cấp mã FRAME99',
            'body' => 'Đề nghị cấp mã',
        ]);
        $service->send($case->fresh(), $user);

        Mail::assertSent(MachineIntakeBchMail::class, fn (MachineIntakeBchMail $mail) =>
            $mail->envelope()->from->address === 'company@example.com'
        );
        $this->assertSame('company', $case->fresh()->bch_sender_profile);
    }

    private function configureSenders(): void
    {
        config([
            'machine_intake_mail.reply_to.address' => 'watched.inbox@gmail.com',
            'machine_intake_mail.senders.test.address' => 'test.sender@gmail.com',
            'machine_intake_mail.senders.company.address' => 'company@example.com',
            'mail.mailers.machine_intake_test.username' => 'test.sender@gmail.com',
            'mail.mailers.machine_intake_test.password' => 'app-password',
            'mail.mailers.machine_intake_company.username' => 'company@example.com',
            'mail.mailers.machine_intake_company.password' => 'app-password',
        ]);
    }

    private function confirmedCase(User $user): MachineIntakeCase
    {
        return MachineIntakeCase::create([
            'reference' => 'TN-2026-000099',
            'status' => 'CONFIRMED',
            'company' => 'VINALPHA',
            'chassis_no' => 'FRAME99',
            'engine_no' => 'ENGINE99',
            'machine_type' => 'Máy xúc bánh lốp',
            'brand' => 'Doosan',
            'model_name' => 'DX55',
            'capacity_class' => 55,
            'manufacture_year' => 2020,
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);
    }
}
