<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DailyImageArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_archive_pairs_four_daily_images_into_two_work_sessions(): void
    {
        $machine = Machine::query()->create([
            'asset_code' => 'VT-XL5024', 'company' => 'VINALPHA',
            'chassis_no' => 'ARCHIVE-CHASSIS', 'status' => 'ACTIVE',
        ]);
        foreach (['06:30', '11:00', '13:30', '18:00'] as $time) {
            $this->createDailyImage($machine, $time);
        }

        $this->actingAs(User::factory()->create())
            ->get('/daily-images?month=2026-08')
            ->assertOk()
            ->assertSee('VT-XL5024')
            ->assertSee('2 ca đủ cặp')
            ->assertSee('06:30')
            ->assertSee('18:00');
    }

    public function test_archive_marks_an_odd_image_count_as_incomplete(): void
    {
        $machine = Machine::query()->create([
            'asset_code' => 'VT-XL5025', 'company' => 'VINALPHA',
            'chassis_no' => 'ARCHIVE-ODD-CHASSIS', 'status' => 'ACTIVE',
        ]);
        foreach (['06:30', '11:00', '13:30'] as $time) {
            $this->createDailyImage($machine, $time);
        }

        $this->actingAs(User::factory()->create())
            ->get('/daily-images?month=2026-08&completeness=incomplete')
            ->assertOk()
            ->assertSee('Thiếu một đầu ca');
    }

    public function test_weekly_images_never_appear_in_daily_archive(): void
    {
        $machine = Machine::query()->create([
            'asset_code' => 'VT-XL5026', 'company' => 'VINALPHA',
            'chassis_no' => 'ARCHIVE-WEEKLY-CHASSIS', 'status' => 'ACTIVE',
        ]);
        $job = $this->createDailyImage($machine, '07:00');
        OcrJob::query()->whereKey($job->id)->update(['document_type' => 'WEEKLY_JOURNAL']);

        $this->actingAs(User::factory()->create())
            ->get('/daily-images?month=2026-08')
            ->assertOk()
            ->assertDontSee('VT-XL5026');
    }

    private function createDailyImage(Machine $machine, string $time): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'daily-archive', 'message_id' => uniqid('message-', true),
            'sender_id' => 'sender-1', 'sender_name' => 'Nguyễn Văn A',
            'sent_at' => '2026-08-27 '.$time.':00', 'received_at' => now(), 'status' => 'STORED',
        ]);
        $path = 'zalo/2026/08/27/'.uniqid().'.jpg';
        Storage::disk('local')->put($path, 'image-'.$time);
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id, 'attachment_index' => 0,
            'original_name' => 'image.jpg', 'storage_disk' => 'local', 'storage_path' => $path,
            'sha256' => hash('sha256', $path), 'mime_type' => 'image/jpeg',
            'byte_size' => 100, 'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'machine_id' => $machine->id,
            'asset_code' => $machine->asset_code,
            'document_type' => 'DAILY_TIMEMARK',
            'extracted_date' => '2026-08-27',
            'extracted_time' => $time,
            'status' => 'COMPLETED',
            'attempts' => 1,
        ])->fresh();
    }
}
