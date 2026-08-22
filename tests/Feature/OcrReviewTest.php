<?php

namespace Tests\Feature;

use App\Models\JournalDocument;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_guest_cannot_access_ocr_review_pages_or_private_image(): void
    {
        $job = $this->createJob('DAILY_TIMEMARK', 'COMPLETED');

        $this->get('/ocr-reviews')->assertRedirect('/login');
        $this->get("/ocr-reviews/{$job->id}")->assertRedirect('/login');
        $this->get("/ocr-reviews/{$job->id}/image")->assertRedirect('/login');
    }

    public function test_authenticated_user_can_filter_ocr_jobs(): void
    {
        $matching = $this->createJob('WEEKLY_JOURNAL', 'EXCEPTION', 'Nguyễn Văn Tuấn');
        $this->createJob('DAILY_TIMEMARK', 'COMPLETED', 'Người khác');

        $this->actingAs(User::factory()->create())
            ->get('/ocr-reviews?status=EXCEPTION&document_type=WEEKLY_JOURNAL&q=Tu%E1%BA%A5n')
            ->assertOk()
            ->assertSee("#{$matching->id}")
            ->assertSee('Nguyễn Văn Tuấn')
            ->assertDontSee('Người khác');
    }

    public function test_authenticated_user_can_review_weekly_rows_and_exception_labels(): void
    {
        $machine = Machine::query()->create([
            'asset_code' => 'VT-XL1186',
            'company' => 'VINALPHA',
            'chassis_no' => 'OCR-REVIEW-CHASSIS',
            'status' => 'ACTIVE',
        ]);
        $job = $this->createJob('WEEKLY_JOURNAL', 'EXCEPTION');
        $job->update([
            'machine_id' => $machine->id,
            'asset_code' => $machine->asset_code,
            'confidence' => 0.83,
            'exceptions' => ['JOURNAL_ROW_EXCEPTION'],
            'processed_at' => now(),
        ]);
        $document = JournalDocument::query()->create([
            'ocr_job_id' => $job->id,
            'machine_id' => $machine->id,
            'asset_code' => $machine->asset_code,
            'confidence' => 0.83,
        ]);
        $document->rows()->create([
            'row_number' => 1,
            'work_date' => '2026-07-14',
            'start_time' => '07:00:00',
            'end_time' => '11:00:00',
            'total_minutes' => 240,
            'work_content' => 'Lắp gen điện',
            'work_location' => 'T9',
            'operator_name' => 'Thủy',
            'confidence' => 0.72,
            'raw_data' => ['text' => '14/7 Sáng Lắp gen điện'],
            'exceptions' => ['LOW_CONFIDENCE'],
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/ocr-reviews/{$job->id}")
            ->assertOk()
            ->assertSee('VT-XL1186')
            ->assertSee('Lắp gen điện')
            ->assertSee('Có dòng nhật trình cần kiểm tra')
            ->assertSee('Độ tin cậy thấp');
    }

    public function test_authenticated_user_can_view_private_source_image(): void
    {
        $job = $this->createJob('DAILY_TIMEMARK', 'COMPLETED');
        Storage::disk('local')->put($job->attachment->storage_path, 'fake-jpeg-content');

        $this->actingAs(User::factory()->create())
            ->get("/ocr-reviews/{$job->id}/image")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }


    public function test_user_can_approve_an_ocr_exception_and_audit_is_recorded(): void
    {
        $job = $this->createJob('DAILY_TIMEMARK', 'EXCEPTION');
        $user = User::factory()->create();

        $this->actingAs($user)->put("/ocr-reviews/{$job->id}", [
            'action' => 'approve',
            'review_notes' => 'Đã đối chiếu ảnh gốc',
        ])->assertRedirect();

        $this->assertDatabaseHas('ocr_jobs', ['id' => $job->id, 'review_status' => 'APPROVED', 'reviewed_by' => $user->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_type' => OcrJob::class, 'subject_id' => $job->id, 'event' => 'ocr.reviewed']);
    }

    public function test_user_can_bulk_approve_pending_jobs(): void
    {
        $first = $this->createJob('DAILY_TIMEMARK', 'EXCEPTION');
        $second = $this->createJob('WEEKLY_JOURNAL', 'EXCEPTION');

        $this->actingAs(User::factory()->create())->post('/ocr-reviews/bulk', [
            'job_ids' => [$first->id, $second->id],
            'action' => 'approve',
        ])->assertRedirect();

        $this->assertDatabaseCount('ocr_jobs', 2);
        $this->assertSame(2, OcrJob::query()->where('review_status', 'APPROVED')->count());
    }


    public function test_dashboard_filters_review_status_and_groups_daily_jobs_by_machine(): void
    {
        $machine = Machine::query()->create([
            'asset_code' => 'VT-XL5024',
            'company' => 'VINALPHA',
            'chassis_no' => 'DASHBOARD-CHASSIS',
            'status' => 'ACTIVE',
        ]);
        $pending = $this->createJob('DAILY_TIMEMARK', 'EXCEPTION');
        $pending->update([
            'machine_id' => $machine->id,
            'asset_code' => $machine->asset_code,
            'extracted_date' => '2026-08-22',
            'extracted_time' => '07:00:00',
            'review_status' => 'PENDING',
        ]);
        $automatic = $this->createJob('DAILY_TIMEMARK', 'COMPLETED');
        OcrJob::query()->whereKey($automatic->id)->update(['review_status' => 'AUTO_APPROVED']);

        $this->actingAs(User::factory()->create())
            ->get('/ocr-reviews?review_status=PENDING&overview_date=2026-08-22')
            ->assertOk()
            ->assertSee("#{$pending->id}")
            ->assertViewHas('jobs', fn ($jobs) => $jobs->contains('id', $pending->id)
                && ! $jobs->contains('id', $automatic->id))
            ->assertSee('VT-XL5024')
            ->assertSee('Tổng quan ảnh hằng ngày theo máy')
            ->assertSee('Áp dụng hàng loạt');
    }


    public function test_user_can_edit_add_and_delete_weekly_rows_with_audit(): void
    {
        $machine = Machine::query()->create([
            'asset_code' => 'VT-XL1186', 'company' => 'VINALPHA',
            'chassis_no' => 'JOURNAL-EDIT-CHASSIS', 'status' => 'ACTIVE',
        ]);
        $job = $this->createJob('WEEKLY_JOURNAL', 'EXCEPTION');
        $document = JournalDocument::query()->create([
            'ocr_job_id' => $job->id, 'confidence' => 0.85,
        ]);
        $first = $document->rows()->create([
            'row_number' => 1, 'work_date' => '2026-07-14',
            'start_time' => '07:00:00', 'end_time' => '11:00:00',
            'total_minutes' => 240, 'work_content' => 'Công việc cũ',
            'confidence' => 0.90,
        ]);
        $second = $document->rows()->create([
            'row_number' => 2, 'work_date' => '2026-07-14',
            'start_time' => '13:30:00', 'end_time' => '17:30:00',
            'total_minutes' => 240, 'work_content' => 'Dòng cần xóa',
            'confidence' => 0.90,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->put("/ocr-reviews/{$job->id}/journal", [
            'action' => 'save',
            'machine_id' => $machine->id,
            'review_notes' => 'Đã sửa theo ảnh gốc',
            'rows' => [
                [
                    'id' => $first->id, 'work_date' => '2026-07-14',
                    'start_time' => '07:00', 'end_time' => '11:30',
                    'work_content' => 'Lắp gen điện', 'work_location' => 'T9',
                    'operator_name' => 'Thủy', 'confidence' => 0.90,
                ],
                ['id' => $second->id, 'delete' => 1],
                [
                    'work_date' => '2026-07-15', 'start_time' => '13:30',
                    'end_time' => '17:30', 'work_content' => 'Đào và lấp gen',
                    'quantity' => 80, 'unit' => 'm', 'work_location' => 'T9',
                    'operator_name' => 'Thủy', 'confidence' => 1,
                ],
            ],
        ])->assertRedirect();

        $this->assertDatabaseCount('journal_rows', 2);
        $this->assertDatabaseHas('journal_rows', [
            'journal_document_id' => $document->id,
            'row_number' => 1, 'work_content' => 'Lắp gen điện',
            'total_minutes' => 270,
        ]);
        $this->assertDatabaseHas('journal_rows', [
            'journal_document_id' => $document->id,
            'row_number' => 2, 'work_content' => 'Đào và lấp gen',
            'total_minutes' => 240,
        ]);
        $this->assertDatabaseMissing('journal_rows', ['id' => $second->id]);
        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id, 'machine_id' => $machine->id,
            'review_status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'ocr.journal_updated',
            'subject_type' => OcrJob::class,
            'subject_id' => $job->id,
        ]);
    }

    public function test_weekly_journal_requires_clean_rows_before_approval(): void
    {
        $machine = Machine::query()->create([
            'asset_code' => 'VT-XL5030', 'company' => 'VINALPHA',
            'chassis_no' => 'JOURNAL-APPROVE-CHASSIS', 'status' => 'ACTIVE',
        ]);
        $job = $this->createJob('WEEKLY_JOURNAL', 'EXCEPTION');
        JournalDocument::query()->create([
            'ocr_job_id' => $job->id, 'confidence' => 0.90,
        ]);

        $this->actingAs(User::factory()->create())
            ->from("/ocr-reviews/{$job->id}")
            ->put("/ocr-reviews/{$job->id}/journal", [
                'action' => 'approve', 'machine_id' => $machine->id,
                'rows' => [[
                    'work_date' => '2026-07-15', 'start_time' => '07:00',
                    'end_time' => '11:00', 'work_content' => '',
                    'confidence' => 1,
                ]],
            ])
            ->assertRedirect("/ocr-reviews/{$job->id}")
            ->assertSessionHasErrors('rows');

        $this->actingAs(User::factory()->create())
            ->put("/ocr-reviews/{$job->id}/journal", [
                'action' => 'approve', 'machine_id' => $machine->id,
                'rows' => [[
                    'work_date' => '2026-07-15', 'start_time' => '07:00',
                    'end_time' => '11:00', 'work_content' => 'Đào và lấp gen',
                    'work_location' => 'T9', 'confidence' => 1,
                ]],
            ])->assertRedirect();

        $this->assertDatabaseHas('ocr_jobs', [
            'id' => $job->id, 'status' => 'COMPLETED',
            'review_status' => 'APPROVED', 'exceptions' => null,
        ]);
    }

    public function test_weekly_overnight_shift_keeps_work_date_and_calculates_duration(): void
    {
        $machine = Machine::query()->create([
            'asset_code' => 'VT-XL5031', 'company' => 'VINALPHA',
            'chassis_no' => 'JOURNAL-OVERNIGHT-CHASSIS', 'status' => 'ACTIVE',
        ]);
        $job = $this->createJob('WEEKLY_JOURNAL', 'EXCEPTION');
        $document = JournalDocument::query()->create([
            'ocr_job_id' => $job->id, 'confidence' => 0.95,
        ]);

        $this->actingAs(User::factory()->create())
            ->put("/ocr-reviews/{$job->id}/journal", [
                'action' => 'save', 'machine_id' => $machine->id,
                'rows' => [[
                    'work_date' => '2026-07-18', 'start_time' => '22:00',
                    'end_time' => '02:00', 'work_content' => 'Làm ca đêm',
                    'confidence' => 1,
                ]],
            ])->assertRedirect();

        $this->assertDatabaseHas('journal_rows', [
            'journal_document_id' => $document->id,
            'work_date' => '2026-07-18',
            'start_time' => '22:00:00',
            'end_time' => '02:00:00',
            'total_minutes' => 240,
        ]);
    }

    private function createJob(string $documentType, string $status, string $senderName = 'Nguyễn Văn A'): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'group-review',
            'message_id' => 'message-'.uniqid(),
            'sender_id' => 'sender-1',
            'sender_name' => $senderName,
            'sent_at' => '2026-08-21 09:00:00',
            'received_at' => '2026-08-21 09:00:05',
            'status' => 'STORED',
        ]);
        $path = 'zalo/2026/08/21/'.uniqid().'.jpg';
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'zalo-image.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', $path),
            'mime_type' => 'image/jpeg',
            'byte_size' => 100,
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'status' => $status,
            'document_type' => $documentType,
            'attempts' => 1,
        ]);
    }
}
