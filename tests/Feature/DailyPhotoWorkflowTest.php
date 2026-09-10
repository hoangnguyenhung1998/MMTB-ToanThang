<?php

namespace Tests\Feature;

use App\Models\OcrJob;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\AiReconciliationService;
use App\Services\DailyPhotoCaseService;
use App\Services\DailyPhotoWorkflowService;
use App\Services\OcrJobService;
use App\Services\OpenClawCommandService;
use App\Services\Reconciliation\DailyPhotoSyncService;
use App\Services\Reconciliation\DailyTimeAllocator;
use App\Services\ZaloSenderDriverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DailyPhotoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_photos.enabled' => true]);
    }

    public function test_weekly_and_ai_claims_are_stopped_and_page_is_maintenance(): void
    {
        [$period, $row] = $this->fixture();
        $weekly = $this->photo($row, '07:00')->fresh();
        $weekly->update(['document_type' => 'WEEKLY_JOURNAL', 'status' => 'PENDING']);
        $this->assertNull(app(OcrJobService::class)->claim('weekly', ['WEEKLY_JOURNAL']));
        $this->assertCount(0, app(AiReconciliationService::class)->claim('ai', '2026-09-05'));
        $this->assertCount(0, app(OpenClawCommandService::class)->claim('ai'));
        $this->assertDatabaseCount('ai_reconciliation_jobs', 0);
        $this->actingAs(User::factory()->create())->get(route('ai-reconciliation.index'))->assertOk()->assertSee('Bảo trì');
        $this->assertSame('PENDING', $weekly->fresh()->status);
    }

    public function test_four_photos_populate_rounded_times_without_weekly_and_sync_is_idempotent(): void
    {
        [$period, $row] = $this->fixture();
        foreach (['06:14', '10:44', '13:55', '18:02'] as $time) {
            $this->photo($row, $time);
        }
        $service = app(DailyPhotoSyncService::class);
        $this->assertSame(1, $service->sync($period)['updated']);
        $fresh = $row->fresh();
        $this->assertSame('06:30', substr($fresh->regular_morning_start, 0, 5));
        $this->assertSame('10:30', substr($fresh->regular_morning_end, 0, 5));
        $this->assertSame('17:00', substr($fresh->regular_afternoon_end, 0, 5));
        $this->assertSame(420, (int) $fresh->regular_minutes);
        $this->assertSame(60, (int) $fresh->ot_afternoon_minutes);
        $this->assertSame('06:14', substr($fresh->ocr_check_in_raw, 0, 5));
        $this->assertSame(0, $service->sync($period)['updated']);
        $this->assertDatabaseCount('journal_documents', 0);
    }

    public function test_missing_photo_does_not_invent_a_shift(): void
    {
        [$period,$row] = $this->fixture();
        $this->photo($row, '06:14');
        app(DailyPhotoSyncService::class)->sync($period);
        $this->assertNull($row->fresh()->regular_minutes);
        $this->assertSame('DAILY_PARTIAL', $row->fresh()->evidence_status);
    }

    public function test_manual_and_reviewed_rows_are_preserved(): void
    {
        [$period,$row] = $this->fixture();
        $row->update(['status' => 'REVIEWED', 'regular_minutes' => 300, 'work_content' => 'Đã sửa']);
        foreach (['06:14', '10:44', '13:55', '18:02'] as $time) {
            $this->photo($row, $time);
        }
        app(DailyPhotoSyncService::class)->sync($period);
        $this->assertSame(300, (int) $row->fresh()->regular_minutes);
        $this->assertSame('REVIEWED', $row->fresh()->status);
        $this->assertTrue($row->fresh()->has_evidence_changes);
        $row->update(['status' => 'DRAFT', 'manually_edited_at' => now()]);
        app(DailyPhotoSyncService::class)->sync($period);
        $this->assertSame('Đã sửa', $row->fresh()->work_content);
    }

    public function test_requeue_keeps_snapshot_and_refuses_reviewed_result(): void
    {
        [, $row] = $this->fixture();
        $job = $this->photo($row, '06:14');
        $user = User::factory()->create();
        app(DailyPhotoWorkflowService::class)->requeue($job, 'DAILY_TIMEMARK', $user->id);
        $this->assertSame('PENDING', $job->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'daily_photo.requeued', 'subject_id' => $job->id]);
        $job->refresh()->update(['reviewed_at' => now()]);
        $this->expectException(ValidationException::class);
        app(DailyPhotoWorkflowService::class)->requeue($job, 'DAILY_TIMEMARK', $user->id);
    }

    public function test_manual_missing_endpoint_requires_confirmation(): void
    {
        [, $row] = $this->fixture();
        $this->expectException(ValidationException::class);
        app(DailyPhotoWorkflowService::class)->allocate($row, ['intervals' => [
            ['kind' => 'regular_morning', 'start' => '06:14', 'end' => '10:44'],
        ]], User::factory()->create()->id);
    }

    public function test_confirmed_manual_interval_uses_rounding_and_preserves_content(): void
    {
        [, $row] = $this->fixture();
        $row->update(['work_content' => 'Lu đất']);
        app(DailyPhotoWorkflowService::class)->allocate($row, ['intervals' => [
            ['kind' => 'regular_morning', 'start' => '06:14', 'end' => '10:44'],
        ], 'manual_reason' => 'Thiếu ảnh ra, đã kiểm tra', 'confirm_manual' => true], User::factory()->create()->id);
        $this->assertSame(240, (int) $row->fresh()->regular_minutes);
        $this->assertSame('Lu đất', $row->fresh()->work_content);
    }

    public function test_overlaps_and_zero_length_rounding_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(DailyTimeAllocator::class)->allocate([
            ['kind' => 'regular_morning', 'start' => '06:14', 'end' => '06:29'],
        ]);
    }

    public function test_sender_mapping_uses_dated_history_and_refuses_ambiguous_assignment(): void
    {
        [, $row] = $this->fixture();
        $job = $this->photo($row, '06:14');
        $driver = \App\Models\Driver::create(['name' => 'Lái máy A']);
        $service = app(ZaloSenderDriverService::class);
        $service->link(['sender_id' => 'sender-1', 'driver_id' => $driver->id, 'valid_from' => '2026-09-01 00:00:00', 'valid_to' => null], User::factory()->create()->id);
        DB::table('machine_driver_histories')->insert(['machine_id' => $row->machine_id, 'driver_id' => $driver->id, 'started_at' => '2026-09-01 00:00:00', 'ended_at' => '2026-09-06 00:00:00']);
        $this->assertSame($row->machine_id, $service->resolve($job, '2026-09-05', '06:14:00')?->id);
        $this->assertNull($service->resolve($job, '2026-09-07', '06:14:00'));
        $other = DB::table('machines')->insertGetId(['asset_code' => 'T-XL9999', 'chassis_no' => 'other', 'company' => 'VINCONS', 'status' => 'ACTIVE', 'returned_to_app' => true, 'gps_file_added' => false]);
        DB::table('machine_driver_histories')->insert(['machine_id' => $other, 'driver_id' => $driver->id, 'started_at' => '2026-09-01 00:00:00']);
        $this->assertNull($service->resolve($job, '2026-09-05', '06:14:00'));
    }

    public function test_midnight_rounding_cannot_move_entry_to_the_wrong_day(): void
    {
        $this->expectException(ValidationException::class);
        app(DailyTimeAllocator::class)->allocate([['kind' => 'overtime_evening', 'start' => '23:59', 'end' => '03:30']]);
    }

    public function test_failed_reocr_does_not_reapprove_old_extracted_fields(): void
    {
        [, $row] = $this->fixture();
        $job = $this->photo($row, '06:14');
        app(DailyPhotoWorkflowService::class)->requeue($job, 'DAILY_TIMEMARK', User::factory()->create()->id);
        $job->refresh()->update(['status' => 'FAILED']);
        $this->assertSame('PENDING', $job->fresh()->review_status);
        $this->assertSame('FAILED', $job->fresh()->status);
    }

    public function test_second_bch_uses_remaining_regular_budget(): void
    {
        [, $row] = $this->fixture();
        $first = $row->replicate();
        $assignment = $row->assignment->replicate();
        $assignment->save();
        $first->machine_assignment_id = $assignment->id;
        $first->segment_end = '12:00:00';
        $first->regular_morning_start = '06:00';
        $first->regular_morning_end = '10:00';
        $first->regular_minutes = 240;
        $first->save();
        app(DailyPhotoWorkflowService::class)->allocate($row, ['intervals' => [
            ['kind' => 'regular_afternoon', 'start' => '14:00', 'end' => '18:00'],
        ], 'manual_reason' => 'Đã kiểm tra ca chiều', 'confirm_manual' => true], User::factory()->create()->id);
        $this->assertSame(180, (int) $row->fresh()->regular_minutes);
        $this->assertSame(60, (int) $row->fresh()->ot_afternoon_minutes);
    }

    public function test_overlapping_an_existing_row_is_rejected(): void
    {
        [, $row] = $this->fixture();
        $other = $row->replicate();
        $assignment = $row->assignment->replicate();
        $assignment->save();
        $other->machine_assignment_id = $assignment->id;
        $other->segment_end = '12:00:00';
        $other->regular_morning_start = '06:00';
        $other->regular_morning_end = '10:00';
        $other->regular_minutes = 240;
        $other->save();
        $this->expectException(ValidationException::class);
        app(DailyPhotoWorkflowService::class)->allocate($row, ['intervals' => [
            ['kind' => 'regular_morning', 'start' => '09:00', 'end' => '11:00'],
        ], 'manual_reason' => 'Kiểm tra', 'confirm_manual' => true], User::factory()->create()->id);
    }

    public function test_overnight_reocr_flags_the_previous_day_without_overwriting_it(): void
    {
        [$period, $row] = $this->fixture();
        $start = $this->photo($row, '19:00');
        $end = $this->photo($row, '03:30');
        $end->update(['extracted_date' => '2026-09-06']);
        $user = User::factory()->create();
        app(DailyPhotoWorkflowService::class)->allocate($row, ['intervals' => [
            ['kind' => 'overtime_evening', 'start_job_id' => $start->id, 'end_job_id' => $end->id],
        ]], $user->id);
        $this->assertSame(510, (int) $row->fresh()->ot_evening_minutes);
        app(DailyPhotoWorkflowService::class)->requeue($end, 'DAILY_TIMEMARK', $user->id);
        $this->assertTrue($row->fresh()->has_evidence_changes);
        $this->assertSame(510, (int) $row->fresh()->ot_evening_minutes);
    }

    public function test_settings_page_and_source_pairing_page_render(): void
    {
        [$period, $row] = $this->fixture();
        $this->photo($row, '06:14');
        $this->actingAs(User::factory()->create());
        $this->get(route('daily-photos.settings'))->assertOk()->assertSee('Zalo');
        $this->get(route('reconciliation-rows.show', [$period, $row]))->assertOk()->assertSee('Ảnh hằng ngày');
    }

    private function fixture(): array
    {
        $project = DB::table('projects')->insertGetId(['name' => 'Dự án']);
        $bch = DB::table('command_centers')->insertGetId(['name' => 'BCH']);
        $machine = DB::table('machines')->insertGetId(['asset_code' => 'VT-XL0196', 'chassis_no' => 'daily-196', 'company' => 'VINALPHA', 'status' => 'ACTIVE', 'returned_to_app' => true, 'gps_file_added' => false]);
        $assignment = DB::table('machine_assignments')->insertGetId(['machine_id' => $machine, 'project_id' => $project, 'command_center_id' => $bch, 'time_in' => '2026-09-01 00:00:00']);
        $period = ReconciliationPeriod::create(['name' => 'Tháng 9', 'type' => 'MONTHLY', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'status' => 'GENERATED']);
        $row = ReconciliationRow::create(['reconciliation_period_id' => $period->id, 'machine_id' => $machine, 'machine_assignment_id' => $assignment, 'project_id' => $project, 'command_center_id' => $bch, 'work_date' => '2026-09-05', 'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT']);

        return [$period, $row];
    }

    private function photo(ReconciliationRow $row, string $time): OcrJob
    {
        $message = ZaloMessage::create(['group_id' => 'group', 'message_id' => (string) Str::uuid(), 'sender_id' => 'sender-1', 'sent_at' => '2026-09-05 07:00:00', 'received_at' => now(), 'status' => 'STORED']);
        $attachment = ZaloAttachment::create(['zalo_message_id' => $message->id, 'attachment_index' => 0, 'storage_disk' => 'local', 'storage_path' => 'test/photo.jpg', 'sha256' => hash('sha256', Str::uuid()), 'mime_type' => 'image/jpeg', 'byte_size' => 10, 'status' => 'STORED']);
        $job = OcrJob::create(['zalo_attachment_id' => $attachment->id, 'document_type' => 'DAILY_TIMEMARK', 'status' => 'COMPLETED', 'machine_id' => $row->machine_id,
            'confidence' => 0.99, 'extracted_date' => '2026-09-05', 'extracted_time' => $time.':00', 'shift' => $time < '11:00' ? 'MORNING' : ($time < '16:30' ? 'AFTERNOON' : 'EVENING_OT')])->fresh();
        app(DailyPhotoCaseService::class)->materialize($job);

        return $job->fresh(['dailyPhotoCase', 'dailyPhotoCaseEvidence']);
    }
}
