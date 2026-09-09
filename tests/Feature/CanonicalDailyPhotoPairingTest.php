<?php

namespace Tests\Feature;

use App\Models\DailyPhotoCase;
use App\Models\DailyPhotoCaseEvidence;
use App\Models\DailyPhotoInterval;
use App\Models\OcrJob;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyPhotoPairingService;
use App\Services\DailyPhotoWorkflowService;
use App\Services\OcrJobService;
use App\Services\OcrReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalDailyPhotoPairingTest extends TestCase
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

    public function test_one_shift_creates_one_ready_interval(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);

        $this->evidence($machine, '07:00:00');
        $last = $this->evidence($machine, '11:00:00');
        $case = $last->dailyPhotoCase;

        $this->assertSame(DailyPhotoCase::STATUS_READY, $case->status);
        $this->assertSame([['07:00:00', '11:00:00']], $this->intervalTimes($case));
        $this->assertSame(2, $case->evidenceMemberships()->where('pairing_state', DailyPhotoCaseEvidence::STATE_PAIRED)->count());
    }

    public function test_two_shifts_create_two_ready_intervals(): void
    {
        $case = $this->caseWithTimes(['07:00:00', '11:00:00', '13:30:00', '17:30:00']);

        $this->assertSame(DailyPhotoCase::STATUS_READY, $case->status);
        $this->assertSame([
            ['07:00:00', '11:00:00'],
            ['13:30:00', '17:30:00'],
        ], $this->intervalTimes($case));
    }

    public function test_three_shifts_have_no_fixed_interval_limit(): void
    {
        $case = $this->caseWithTimes(['07:03:00', '11:02:00', '13:28:00', '17:34:00', '18:05:00', '20:12:00']);

        $this->assertSame(DailyPhotoCase::STATUS_READY, $case->status);
        $this->assertSame([
            ['07:03:00', '11:02:00'],
            ['13:28:00', '17:34:00'],
            ['18:05:00', '20:12:00'],
        ], $this->intervalTimes($case));
    }

    public function test_odd_evidence_is_retained_then_paired_when_the_next_image_arrives(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);
        $this->evidence($machine, '07:00:00');
        $this->evidence($machine, '11:00:00');
        $third = $this->evidence($machine, '13:30:00');
        $case = $third->dailyPhotoCase;

        $this->assertSame(DailyPhotoCase::STATUS_COLLECTING, $case->status);
        $this->assertSame([['07:00:00', '11:00:00']], $this->intervalTimes($case));
        $this->assertSame([$third->dailyPhotoCaseEvidence->id], $case->pairing_diagnostics['unmatched_evidence_ids']);
        $this->assertSame(DailyPhotoCaseEvidence::STATE_UNMATCHED, $third->dailyPhotoCaseEvidence->pairing_state);

        $fourth = $this->evidence($machine, '17:30:00');
        $case = $fourth->dailyPhotoCase;
        $this->assertSame(DailyPhotoCase::STATUS_READY, $case->status);
        $this->assertSame([
            ['07:00:00', '11:00:00'],
            ['13:30:00', '17:30:00'],
        ], $this->intervalTimes($case));
        $this->assertSame([], $case->pairing_diagnostics['unmatched_evidence_ids']);
    }

    public function test_arrival_order_does_not_change_capture_time_pairing(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);
        $last = null;
        foreach (['17:30:00', '07:00:00', '13:30:00', '11:00:00'] as $index => $time) {
            $last = $this->evidence($machine, $time, '2026-09-09', "2026-09-10 0{$index}:00:00");
        }

        $this->assertSame([
            ['07:00:00', '11:00:00'],
            ['13:30:00', '17:30:00'],
        ], $this->intervalTimes($last->dailyPhotoCase));
    }

    public function test_next_day_zalo_send_still_pairs_by_capture_date(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);
        $this->evidence($machine, '07:00:00', '2026-09-09', '2026-09-10 08:00:00');
        $last = $this->evidence($machine, '11:00:00', '2026-09-09', '2026-09-10 08:01:00');

        $this->assertSame('2026-09-09', $last->dailyPhotoCase->work_date->format('Y-m-d'));
        $this->assertSame([['07:00:00', '11:00:00']], $this->intervalTimes($last->dailyPhotoCase));
    }

    public function test_duplicate_timestamp_is_ambiguous_and_never_selected_by_job_id(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);
        $first = $this->evidence($machine, '07:00:00');
        $second = $this->evidence($machine, '07:00:00');
        $last = $this->evidence($machine, '11:00:00');
        $case = $last->dailyPhotoCase;

        $this->assertSame(DailyPhotoCase::STATUS_PAIRING_AMBIGUOUS, $case->status);
        $this->assertContains('DUPLICATE_TIMESTAMP', $case->pairing_diagnostics['codes']);
        $this->assertDatabaseCount('daily_photo_intervals', 0);
        $this->assertSame(DailyPhotoCaseEvidence::STATE_AMBIGUOUS, $first->fresh()->dailyPhotoCaseEvidence->pairing_state);
        $this->assertSame(DailyPhotoCaseEvidence::STATE_AMBIGUOUS, $second->fresh()->dailyPhotoCaseEvidence->pairing_state);
    }

    public function test_recompute_is_idempotent_and_keeps_interval_identity(): void
    {
        $case = $this->caseWithTimes(['07:00:00', '11:00:00', '13:30:00', '17:30:00']);
        $before = $case->intervals()->orderBy('sequence')->get()->map->only([
            'id', 'sequence', 'start_evidence_id', 'end_evidence_id', 'raw_start_time', 'raw_end_time',
        ])->all();

        for ($i = 0; $i < 10; $i++) {
            app(DailyPhotoPairingService::class)->recompute($case);
        }

        $after = $case->intervals()->orderBy('sequence')->get()->map->only([
            'id', 'sequence', 'start_evidence_id', 'end_evidence_id', 'raw_start_time', 'raw_end_time',
        ])->all();
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('daily_photo_intervals', 2);
        $this->assertDatabaseCount('daily_photo_case_evidence', 4);
    }

    public function test_human_time_correction_recomputes_intervals(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);
        $this->evidence($machine, '07:00:00');
        $this->evidence($machine, '11:00:00');
        $changed = $this->evidence($machine, '13:00:00');
        $this->evidence($machine, '17:30:00');

        $reviewed = app(OcrReviewService::class)->review($changed, [
            'action' => 'correct',
            'machine_id' => $machine,
            'extracted_time' => '13:30:00',
        ], User::factory()->create());

        $this->assertSame([
            ['07:00:00', '11:00:00'],
            ['13:30:00', '17:30:00'],
        ], $this->intervalTimes($reviewed->dailyPhotoCase));
        $this->assertSame('13:30:00', $reviewed->dailyPhotoCaseEvidence->capture_datetime->format('H:i:s'));
    }

    public function test_machine_and_date_change_moves_membership_and_recomputes_both_cases(): void
    {
        $machineA = $this->machine('T-XL0034');
        $machineB = $this->machine('T-XL0354');
        $this->assignment($machineA);
        $this->assignment($machineB);
        $this->evidence($machineA, '07:00:00');
        $moved = $this->evidence($machineA, '11:00:00');
        $oldCase = $moved->dailyPhotoCase;

        $reviewed = app(OcrReviewService::class)->review($moved, [
            'action' => 'correct',
            'machine_id' => $machineB,
            'extracted_date' => '2026-09-10',
            'extracted_time' => '11:00:00',
        ], User::factory()->create());

        $newCase = $reviewed->dailyPhotoCase;
        $this->assertNotSame($oldCase->id, $newCase->id);
        $this->assertSame(DailyPhotoCase::STATUS_COLLECTING, $oldCase->fresh()->status);
        $this->assertSame(DailyPhotoCase::STATUS_COLLECTING, $newCase->status);
        $this->assertSame(1, $oldCase->evidenceMemberships()->count());
        $this->assertSame(1, $newCase->evidenceMemberships()->count());
        $this->assertSame(1, DailyPhotoCaseEvidence::query()->where('ocr_job_id', $moved->id)->count());
        $this->assertDatabaseCount('daily_photo_intervals', 0);
    }

    public function test_multi_assignment_ambiguity_blocks_pairing(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);
        $this->assignment($machine, '2026-09-08 00:00:00');
        $this->evidence($machine, '07:00:00');
        $last = $this->evidence($machine, '11:00:00');
        $case = $last->dailyPhotoCase;

        $this->assertNull($case->machine_assignment_id);
        $this->assertSame(DailyPhotoCase::STATUS_PAIRING_AMBIGUOUS, $case->status);
        $this->assertContains('ASSIGNMENT_AMBIGUOUS', $case->pairing_diagnostics['codes']);
        $this->assertDatabaseCount('daily_photo_intervals', 0);
    }

    public function test_active_near_duplicate_candidates_block_pairing(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);
        $fingerprint = '0123456789abcdef';
        $this->evidence($machine, '07:00:00', fingerprint: $fingerprint);
        $last = $this->evidence($machine, '11:00:00', fingerprint: $fingerprint);

        $this->assertSame(DailyPhotoCase::STATUS_PAIRING_AMBIGUOUS, $last->dailyPhotoCase->status);
        $this->assertContains('NEAR_DUPLICATE', $last->dailyPhotoCase->pairing_diagnostics['codes']);
        $this->assertDatabaseCount('daily_photo_intervals', 0);
    }

    public function test_requeue_detaches_membership_and_recomputes_the_old_case(): void
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);
        $this->evidence($machine, '07:00:00');
        $removed = $this->evidence($machine, '11:00:00');
        $case = $removed->dailyPhotoCase;

        app(DailyPhotoWorkflowService::class)->requeue(
            $removed,
            'DAILY_TIMEMARK',
            User::factory()->create()->id,
        );

        $this->assertNull($removed->fresh()->daily_photo_case_id);
        $this->assertNull($removed->fresh()->dailyPhotoCaseEvidence);
        $this->assertSame(DailyPhotoCase::STATUS_COLLECTING, $case->fresh()->status);
        $this->assertSame(1, $case->evidenceMemberships()->count());
        $this->assertDatabaseCount('daily_photo_intervals', 0);
    }

    private function caseWithTimes(array $times): DailyPhotoCase
    {
        $machine = $this->machine('T-XL0034');
        $this->assignment($machine);
        $last = null;
        foreach ($times as $time) {
            $last = $this->evidence($machine, $time);
        }

        return $last->dailyPhotoCase;
    }

    private function intervalTimes(DailyPhotoCase $case): array
    {
        return $case->intervals()->orderBy('sequence')->get()
            ->map(fn (DailyPhotoInterval $interval) => [
                substr((string) $interval->raw_start_time, 0, 8),
                substr((string) $interval->raw_end_time, 0, 8),
            ])->all();
    }

    private function evidence(
        int $machineId,
        string $time,
        string $date = '2026-09-09',
        string $sentAt = '2026-09-09 08:00:00',
        ?string $fingerprint = null,
    ): OcrJob {
        $message = ZaloMessage::query()->create([
            'group_id' => 'group-1',
            'message_id' => (string) Str::uuid(),
            'sender_id' => 'sender-1',
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
        $job = OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'document_type' => 'DAILY_TIMEMARK',
            'status' => 'PENDING',
        ]);
        $claimed = app(OcrJobService::class)->claim('pairing-worker', ['DAILY_TIMEMARK']);
        $this->assertSame($job->id, $claimed?->id);

        return app(OcrJobService::class)->complete($claimed, [
            'worker_id' => 'pairing-worker',
            'attempt' => $claimed->attempts,
            'date' => $date,
            'time' => $time,
            'asset_code' => DB::table('machines')->where('id', $machineId)->value('asset_code'),
            'confidence' => 0.99,
            'image_fingerprint' => $fingerprint,
        ])->fresh(['dailyPhotoCase', 'dailyPhotoCaseEvidence']);
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

    private function assignment(int $machineId, string $from = '2026-09-01 00:00:00', ?string $to = null): int
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
}
