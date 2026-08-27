<?php

namespace Tests\Feature;

use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\OcrJob;
use App\Models\Project;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DailyImageExceptionCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_active_assignment_without_images_is_reported_automatically(): void
    {
        $machine = $this->assignedMachine('VT-XL5101');

        $this->actingAs(User::factory()->create())
            ->get('/daily-images/exceptions?date_from=2026-08-27&date_to=2026-08-27&exception_status=NO_IMAGES')
            ->assertOk()
            ->assertSee($machine->asset_code)
            ->assertSee('Chưa có ảnh');
    }

    public function test_four_approved_images_are_completed_without_manual_confirmation(): void
    {
        $machine = $this->assignedMachine('VT-XL5102');
        foreach (['06:30', '11:00', '13:30', '18:00'] as $time) {
            $this->createDailyImage($machine, $time);
        }

        $this->actingAs(User::factory()->create())
            ->get('/daily-images/exceptions?date_from=2026-08-27&date_to=2026-08-27&exception_status=AUTO_COMPLETE')
            ->assertOk()
            ->assertSee($machine->asset_code)
            ->assertSee('Hoàn thành tự động');
    }

    public function test_two_images_are_kept_for_future_ctms_confirmation(): void
    {
        $machine = $this->assignedMachine('VT-XL5103');
        foreach (['06:30', '11:00'] as $time) {
            $this->createDailyImage($machine, $time);
        }

        $this->actingAs(User::factory()->create())
            ->get('/daily-images/exceptions?date_from=2026-08-27&date_to=2026-08-27&exception_status=CTMS_PENDING')
            ->assertOk()
            ->assertSee($machine->asset_code)
            ->assertSee('Chờ CTMS xác nhận số ca');
    }

    public function test_pending_ocr_takes_priority_over_image_count(): void
    {
        $machine = $this->assignedMachine('VT-XL5104');
        $this->createDailyImage($machine, '06:30', 'PENDING');

        $this->actingAs(User::factory()->create())
            ->get('/daily-images/exceptions?date_from=2026-08-27&date_to=2026-08-27&exception_status=PENDING_REVIEW')
            ->assertOk()
            ->assertSee($machine->asset_code)
            ->assertSee('Chờ hậu kiểm OCR');
    }

    private function assignedMachine(string $assetCode): Machine
    {
        $machine = Machine::query()->create([
            'asset_code' => $assetCode,
            'company' => 'VINALPHA',
            'chassis_no' => 'CHASSIS-'.$assetCode,
            'status' => 'ACTIVE',
        ]);
        $project = Project::query()->firstOrCreate(['name' => 'Dự án Phase 15.5']);
        $commandCenter = CommandCenter::query()->firstOrCreate(['name' => 'TĐXD 01']);
        MachineAssignment::query()->create([
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'command_center_id' => $commandCenter->id,
            'time_in' => '2026-08-01 07:00:00',
            'time_out' => null,
        ]);

        return $machine;
    }

    private function createDailyImage(Machine $machine, string $time, string $status = 'COMPLETED'): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'phase-15-5',
            'message_id' => uniqid('message-', true),
            'sender_id' => 'sender-1',
            'sender_name' => 'Nguyễn Văn A',
            'sent_at' => '2026-08-27 '.$time.':00',
            'received_at' => now(),
            'status' => 'STORED',
        ]);
        $path = 'zalo/2026/08/27/'.uniqid().'.jpg';
        Storage::disk('local')->put($path, 'image-'.$time);
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'image.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', $path),
            'mime_type' => 'image/jpeg',
            'byte_size' => 100,
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'machine_id' => $machine->id,
            'asset_code' => $machine->asset_code,
            'document_type' => 'DAILY_TIMEMARK',
            'extracted_date' => '2026-08-27',
            'extracted_time' => $time,
            'status' => $status,
            'attempts' => 1,
        ])->fresh();
    }
}
