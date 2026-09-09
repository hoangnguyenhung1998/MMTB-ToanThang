<?php

namespace Tests\Feature;

use App\Models\DailyPhotoCase;
use App\Models\Driver;
use App\Models\MachineDriverHistory;
use App\Models\OcrJob;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyPhotoCaseService;
use App\Services\DailyPhotoMachineResolutionService;
use App\Services\OcrJobService;
use App\Services\OcrReviewService;
use App\Services\ZaloSenderDriverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalDailyPhotoFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'daily_photos.enabled' => true,
            'daily_photos.capture_timezone' => 'Asia/Ho_Chi_Minh',
        ]);
    }

    public function test_image_machine_is_authoritative_when_sender_resolves_another_machine(): void
    {
        $imageMachine = $this->machine('T-XL0034');
        $senderMachine = $this->machine('T-XL0354');
        $this->assignment($imageMachine, '2026-09-01 00:00:00');
        $job = $this->pendingJob('sender-1', '2026-09-09 08:00:00');
        $this->mapSender('sender-1', $senderMachine);

        $completed = $this->complete($job, '2026-09-09', '17:00:00', 'T-XL0034');

        $this->assertSame($imageMachine, $completed->machine_id);
        $this->assertSame('T-XL0034', $completed->observed_asset_code);
        $this->assertSame(DailyPhotoMachineResolutionService::IMAGE_ASSET, $completed->machine_resolution_method);
        $this->assertTrue($completed->machine_resolution_metadata['sender_resolution_mismatch']);
        $this->assertSame($senderMachine, $completed->machine_resolution_metadata['sender_resolution_machine_id']);
        $this->assertNotContains('SENDER_MACHINE_CONFLICT', $completed->exceptions ?? []);
        $this->assertNotNull($completed->daily_photo_case_id);
    }

    public function test_missing_image_asset_uses_deterministic_sender_driver_history(): void
    {
        $machine = $this->machine('T-XL0354');
        $this->assignment($machine, '2026-09-01 00:00:00');
        $job = $this->pendingJob();
        [$linkId, $historyId] = $this->mapSender('sender-1', $machine);

        $completed = $this->complete($job, '2026-09-09', '07:00:00', null);

        $this->assertSame($machine, $completed->machine_id);
        $this->assertNull($completed->observed_asset_code);
        $this->assertSame('T-XL0354', $completed->asset_code);
        $this->assertSame(DailyPhotoMachineResolutionService::SENDER_DRIVER_HISTORY, $completed->machine_resolution_method);
        $this->assertSame($linkId, $completed->sender_driver_link_id);
        $this->assertSame($historyId, $completed->machine_driver_history_id);
        $this->assertNotNull($completed->daily_photo_case_id);
    }

    public function test_unknown_observed_asset_can_fall_back_without_losing_observation(): void
    {
        $machine = $this->machine('T-XL0354');
        $this->assignment($machine, '2026-09-01 00:00:00');
        $job = $this->pendingJob();
        $this->mapSender('sender-1', $machine);

        $completed = $this->complete($job, '2026-09-09', '07:00:00', 'OCR-UNKNOWN');

        $this->assertSame($machine, $completed->machine_id);
        $this->assertSame('OCR-UNKNOWN', $completed->observed_asset_code);
        $this->assertSame('OCR-UNKNOWN', $completed->asset_code);
        $this->assertSame(DailyPhotoMachineResolutionService::SENDER_DRIVER_HISTORY, $completed->machine_resolution_method);
        $this->assertNotContains('UNKNOWN_ASSET_CODE', $completed->exceptions ?? []);
    }

    public function test_unresolved_evidence_does_not_create_a_canonical_case(): void
    {
        $job = $this->pendingJob('unmapped-sender');

        $completed = $this->complete($job, '2026-09-09', '07:00:00', null);

        $this->assertNull($completed->machine_id);
        $this->assertNull($completed->machine_resolution_method);
        $this->assertSame('EXCEPTION', $completed->status);
        $this->assertContains('MISSING_ASSET_CODE', $completed->exceptions);
        $this->assertNull($completed->daily_photo_case_id);
        $this->assertDatabaseCount('daily_photo_cases', 0);
    }

    public function test_work_date_comes_from_capture_date_not_next_day_zalo_sent_at(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine, '2026-09-01 00:00:00');
        $job = $this->pendingJob('sender-1', '2026-09-09 08:00:00');

        $completed = $this->complete($job, '2026-09-08', '17:00:00', 'T-XL0034');

        $this->assertSame('2026-09-08', $completed->dailyPhotoCase->work_date->format('Y-m-d'));
        $this->assertSame('2026-09-09', $completed->attachment->message->sent_at->format('Y-m-d'));
    }

    public function test_materializing_the_same_job_repeatedly_is_idempotent(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine, '2026-09-01 00:00:00');
        $completed = $this->complete($this->pendingJob(), '2026-09-09', '07:00:00', 'T-XL0034');

        $service = app(DailyPhotoCaseService::class);
        $first = $service->materialize($completed);
        $second = $service->materialize($completed->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('daily_photo_cases', 1);
        $this->assertSame($first->id, $completed->fresh()->daily_photo_case_id);
    }

    public function test_two_evidence_rows_for_the_same_scope_create_one_case(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine, '2026-09-01 00:00:00');

        $first = $this->complete($this->pendingJob(), '2026-09-09', '07:00:00', 'T-XL0034');
        $second = $this->complete($this->pendingJob(), '2026-09-09', '17:00:00', 'T-XL0034');

        $this->assertSame($first->daily_photo_case_id, $second->daily_photo_case_id);
        $this->assertDatabaseCount('daily_photo_cases', 1);
        $this->assertSame(2, DailyPhotoCase::query()->firstOrFail()->ocrJobs()->count());
    }

    public function test_overlapping_assignments_are_not_selected_randomly(): void
    {
        $machine = $this->machine('T-XL0034');
        $firstAssignment = $this->assignment($machine, '2026-09-01 00:00:00');
        $secondAssignment = $this->assignment($machine, '2026-09-08 00:00:00');

        $completed = $this->complete($this->pendingJob(), '2026-09-09', '07:00:00', 'T-XL0034');
        $case = $completed->dailyPhotoCase;

        $this->assertNull($case->machine_assignment_id);
        $this->assertSame('AMBIGUOUS', $completed->daily_metadata['case_materialization']['assignment_resolution_status']);
        $this->assertEqualsCanonicalizing(
            [$firstAssignment, $secondAssignment],
            $completed->daily_metadata['case_materialization']['candidate_machine_assignment_ids'],
        );
    }

    public function test_same_machine_can_have_separate_cases_for_two_assignments_on_one_day(): void
    {
        $machine = $this->machine('T-XL0034');
        $morning = $this->assignment($machine, '2026-09-09 00:00:00', '2026-09-09 12:00:00');
        $afternoon = $this->assignment($machine, '2026-09-09 12:00:00');

        $first = $this->complete($this->pendingJob(), '2026-09-09', '07:00:00', 'T-XL0034');
        $second = $this->complete($this->pendingJob(), '2026-09-09', '17:00:00', 'T-XL0034');

        $this->assertNotSame($first->daily_photo_case_id, $second->daily_photo_case_id);
        $this->assertSame($morning, $first->dailyPhotoCase->machine_assignment_id);
        $this->assertSame($afternoon, $second->dailyPhotoCase->machine_assignment_id);
        $this->assertDatabaseCount('daily_photo_cases', 2);
    }

    public function test_human_correction_records_resolution_provenance_and_materializes_case(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine, '2026-09-01 00:00:00');
        $unresolved = $this->complete($this->pendingJob('unmapped-sender'), '2026-09-09', '07:00:00', null);
        $reviewer = User::factory()->create();

        $reviewed = app(OcrReviewService::class)->review($unresolved, [
            'action' => 'correct',
            'machine_id' => $machine,
        ], $reviewer);

        $this->assertSame($machine, $reviewed->machine_id);
        $this->assertSame(DailyPhotoMachineResolutionService::HUMAN, $reviewed->machine_resolution_method);
        $this->assertSame($reviewer->id, $reviewed->machine_resolution_metadata['human_resolution']['resolved_by']);
        $this->assertNotNull($reviewed->machine_resolved_at);
        $this->assertNotNull($reviewed->daily_photo_case_id);
    }

    private function complete(OcrJob $job, string $date, string $time, ?string $assetCode): OcrJob
    {
        $claimed = app(OcrJobService::class)->claim('foundation-worker', ['DAILY_TIMEMARK']);
        $this->assertSame($job->id, $claimed?->id);

        return app(OcrJobService::class)->complete($claimed, [
            'worker_id' => 'foundation-worker',
            'attempt' => $claimed->attempts,
            'date' => $date,
            'time' => $time,
            'asset_code' => $assetCode,
            'confidence' => 0.99,
        ]);
    }

    private function pendingJob(string $senderId = 'sender-1', string $sentAt = '2026-09-09 07:00:00'): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'group-1',
            'message_id' => (string) Str::uuid(),
            'sender_id' => $senderId,
            'sent_at' => $sentAt,
            'received_at' => $sentAt,
            'status' => 'STORED',
        ]);
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'storage_disk' => 'local',
            'storage_path' => 'test/'.Str::uuid().'.jpg',
            'sha256' => hash('sha256', (string) Str::uuid()),
            'mime_type' => 'image/jpeg',
            'byte_size' => 10,
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'document_type' => 'DAILY_TIMEMARK',
            'status' => 'PENDING',
        ]);
    }

    private function machine(string $assetCode): int
    {
        return DB::table('machines')->insertGetId([
            'asset_code' => $assetCode,
            'chassis_no' => 'chassis-'.Str::uuid(),
            'company' => 'VINCONS',
            'status' => 'ACTIVE',
            'returned_to_app' => true,
            'gps_file_added' => false,
        ]);
    }

    private function assignment(int $machineId, string $from, ?string $to = null): int
    {
        $project = DB::table('projects')->insertGetId(['name' => 'Dự án '.Str::random(8)]);
        $commandCenter = DB::table('command_centers')->insertGetId(['name' => 'BCH '.Str::random(8)]);

        return DB::table('machine_assignments')->insertGetId([
            'machine_id' => $machineId,
            'project_id' => $project,
            'command_center_id' => $commandCenter,
            'time_in' => $from,
            'time_out' => $to,
        ]);
    }

    private function mapSender(string $senderId, int $machineId): array
    {
        $driver = Driver::query()->create(['name' => 'Lái máy '.Str::random(8)]);
        app(ZaloSenderDriverService::class)->link([
            'sender_id' => $senderId,
            'driver_id' => $driver->id,
            'valid_from' => '2026-09-01 00:00:00',
            'valid_to' => null,
        ], User::factory()->create()->id);
        $linkId = DB::table('zalo_sender_driver_links')->where('sender_id', $senderId)->value('id');
        $history = MachineDriverHistory::query()->create([
            'machine_id' => $machineId,
            'driver_id' => $driver->id,
            'started_at' => '2026-09-01 00:00:00',
        ]);

        return [$linkId, $history->id];
    }
}
