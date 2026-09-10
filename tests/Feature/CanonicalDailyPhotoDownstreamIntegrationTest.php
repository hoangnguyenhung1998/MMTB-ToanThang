<?php

namespace Tests\Feature;

use App\Models\CommandCenter;
use App\Models\DailyPhotoCase;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\OcrJob;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyImageArchiveService;
use App\Services\DailyImageExceptionService;
use App\Services\DailyPhotoCaseService;
use App\Services\DailyPhotoWorkflowService;
use App\Services\OcrReviewService;
use App\Services\Reconciliation\DailyPhotoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalDailyPhotoDownstreamIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_photos.enabled' => true]);
        Storage::fake('local');
    }

    public function test_ready_cases_with_one_two_and_three_shifts_drive_downstream_allocation(): void
    {
        [$period, $one] = $this->fixture('VT-XL6101');
        [, $two] = $this->fixture('VT-XL6102', $period);
        [, $three] = $this->fixture('VT-XL6103', $period);

        $this->photos($one, ['06:14', '10:44']);
        $this->photos($two, ['06:14', '10:44', '13:55', '18:02']);
        $this->photos($three, ['07:03', '11:02', '13:28', '17:34', '18:05', '20:12']);

        $result = app(DailyPhotoSyncService::class)->sync($period);

        $this->assertSame(3, $result['updated']);
        $this->assertSame('DAILY_READY', $one->fresh()->evidence_status);
        $this->assertCount(1, $one->fresh()->daily_intervals);
        $this->assertSame(240, (int) $one->fresh()->regular_minutes);
        $this->assertCount(2, $two->fresh()->daily_intervals);
        $this->assertSame(420, (int) $two->fresh()->regular_minutes);
        $this->assertSame(60, (int) $two->fresh()->ot_afternoon_minutes);
        $this->assertCount(3, $three->fresh()->daily_intervals);
        $this->assertSame(120, (int) $three->fresh()->ot_evening_minutes);
        $this->assertNotNull($three->fresh()->daily_intervals[2]['canonical_interval_id']);
    }

    public function test_collecting_case_waits_without_inventing_hours(): void
    {
        [$period, $row] = $this->fixture('VT-XL6110');
        $job = $this->photo($row, '06:14');

        app(DailyPhotoSyncService::class)->sync($period);

        $this->assertSame(DailyPhotoCase::STATUS_COLLECTING, $job->dailyPhotoCase->status);
        $this->assertSame('DAILY_PARTIAL', $row->fresh()->evidence_status);
        $this->assertNull($row->fresh()->regular_minutes);
        $this->assertSame([], $row->fresh()->daily_intervals);
    }

    public function test_pairing_ambiguity_fails_closed_without_hours(): void
    {
        [$period, $row] = $this->fixture('VT-XL6111');
        $first = $this->photo($row, '06:14');
        $this->photo($row, '06:14');
        $this->photo($row, '10:44');

        app(DailyPhotoSyncService::class)->sync($period);

        $this->assertSame(DailyPhotoCase::STATUS_PAIRING_AMBIGUOUS, $first->dailyPhotoCase->fresh()->status);
        $this->assertSame('DAILY_REVIEW', $row->fresh()->evidence_status);
        $this->assertNull($row->fresh()->regular_minutes);
        $this->assertSame([], $row->fresh()->daily_intervals);
    }

    public function test_repeated_canonical_sync_is_idempotent(): void
    {
        [$period, $row] = $this->fixture('VT-XL6112');
        $this->photos($row, ['06:14', '10:44']);
        $service = app(DailyPhotoSyncService::class);

        $this->assertSame(1, $service->sync($period)['updated']);
        $signature = $row->fresh()->evidence_signature;
        $this->assertSame(0, $service->sync($period)['updated']);
        $this->assertSame($signature, $row->fresh()->evidence_signature);
        $this->assertSame(240, (int) $row->fresh()->regular_minutes);
    }

    public function test_human_correction_recomputes_and_refreshes_downstream(): void
    {
        [$period, $row] = $this->fixture('VT-XL6113');
        $jobs = $this->photos($row, ['06:14', '10:44']);
        app(DailyPhotoSyncService::class)->sync($period);

        $reviewed = app(OcrReviewService::class)->review($jobs->last(), [
            'action' => 'correct',
            'machine_id' => $row->machine_id,
            'extracted_time' => '11:14:00',
        ], User::factory()->create());

        $case = $reviewed->dailyPhotoCase()->with('intervals')->firstOrFail();
        $this->assertSame(DailyPhotoCase::STATUS_READY, $case->status);
        $this->assertSame('11:14', $case->intervals->last()->raw_end_at->format('H:i'));
        $fresh = $row->fresh();
        $this->assertSame('11:00', substr($fresh->regular_morning_end, 0, 5));
        $this->assertSame('11:14', substr($fresh->ocr_check_out_raw, 0, 5));
        $this->assertSame(270, (int) $fresh->regular_minutes);
        $this->assertSame('DAILY_READY', $fresh->evidence_status);
    }

    public function test_requeue_removes_stale_automatic_result(): void
    {
        [$period, $row] = $this->fixture('VT-XL6114');
        $jobs = $this->photos($row, ['06:14', '10:44']);
        app(DailyPhotoSyncService::class)->sync($period);

        app(DailyPhotoWorkflowService::class)->requeue(
            $jobs->last(),
            'DAILY_TIMEMARK',
            User::factory()->create()->id,
        );

        $case = DailyPhotoCase::query()
            ->where('machine_assignment_id', $row->machine_assignment_id)
            ->whereDate('work_date', $row->work_date)
            ->firstOrFail();
        $this->assertSame(DailyPhotoCase::STATUS_COLLECTING, $case->status);
        $this->assertCount(1, $case->evidenceMemberships);
        $fresh = $row->fresh();
        $this->assertSame('DAILY_PARTIAL', $fresh->evidence_status);
        $this->assertNull($fresh->regular_minutes);
        $this->assertSame([], $fresh->daily_intervals);
        $this->assertSame('PENDING', $jobs->last()->fresh()->status);
    }

    public function test_reviewed_and_manual_rows_are_not_overwritten(): void
    {
        [$period, $row] = $this->fixture('VT-XL6115');
        $row->update([
            'status' => 'REVIEWED',
            'regular_minutes' => 300,
            'work_content' => 'Nội dung đã duyệt',
        ]);
        $this->photos($row, ['06:14', '10:44']);

        $result = app(DailyPhotoSyncService::class)->sync($period);

        $fresh = $row->fresh();
        $this->assertSame(1, $result['protected']);
        $this->assertSame('REVIEWED', $fresh->status);
        $this->assertSame(300, (int) $fresh->regular_minutes);
        $this->assertSame('Nội dung đã duyệt', $fresh->work_content);
        $this->assertTrue($fresh->has_evidence_changes);
    }

    public function test_exception_center_and_archive_use_canonical_case_state_and_intervals(): void
    {
        [, $ready] = $this->fixture('VT-XL6120');
        [, $collecting] = $this->fixture('VT-XL6121');
        [, $ambiguous] = $this->fixture('VT-XL6122');
        $readyJobs = $this->photos($ready, ['06:14', '10:44']);
        $this->photo($collecting, '06:14');
        $this->photo($ambiguous, '06:14', '0123456789abcdef');
        $this->photo($ambiguous, '10:44', '0123456789abcdef');

        $groups = app(DailyImageExceptionService::class)->groups([
            'date_from' => '2026-09-05',
            'date_to' => '2026-09-05',
        ])->keyBy('machine_code');

        $this->assertSame('AUTO_COMPLETE', $groups['VT-XL6120']['status']);
        $this->assertSame('MISSING_MARK', $groups['VT-XL6121']['status']);
        $this->assertSame('PAIRING_AMBIGUOUS', $groups['VT-XL6122']['status']);
        $this->assertTrue($groups['VT-XL6122']['is_exception']);

        $archive = app(DailyImageArchiveService::class)->groups([
            'date_from' => '2026-09-05',
            'date_to' => '2026-09-05',
            'machine_id' => $ready->machine_id,
        ])->sole();
        $case = $readyJobs->last()->dailyPhotoCase;
        $this->assertTrue($archive['is_complete']);
        $this->assertSame(1, $archive['session_count']);
        $this->assertSame($case->intervals()->firstOrFail()->startEvidence->ocr_job_id, $archive['sessions']->first()['start']->id);

        $ambiguousArchive = app(DailyImageArchiveService::class)->groups([
            'date_from' => '2026-09-05',
            'date_to' => '2026-09-05',
            'machine_id' => $ambiguous->machine_id,
        ])->sole();
        $this->assertFalse($ambiguousArchive['is_complete']);
        $this->assertSame(0, $ambiguousArchive['session_count']);
        $this->assertCount(2, $ambiguousArchive['sessions']);
        $this->assertTrue($ambiguousArchive['sessions']->every(fn (array $session) => $session['end'] === null));
    }

    private function fixture(string $assetCode, ?ReconciliationPeriod $period = null): array
    {
        $project = Project::query()->create(['name' => 'Dự án '.$assetCode]);
        $commandCenter = CommandCenter::query()->create(['name' => 'BCH '.$assetCode]);
        $machine = Machine::query()->create([
            'asset_code' => $assetCode,
            'chassis_no' => 'CHASSIS-'.$assetCode,
            'company' => 'VINCONS',
            'status' => 'ACTIVE',
            'returned_to_app' => true,
            'gps_file_added' => false,
        ]);
        $assignment = MachineAssignment::query()->create([
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'command_center_id' => $commandCenter->id,
            'time_in' => '2026-09-01 00:00:00',
        ]);
        $period ??= ReconciliationPeriod::query()->create([
            'name' => 'Tháng 9/2026',
            'type' => 'MONTHLY',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
            'status' => 'GENERATED',
        ]);
        $row = ReconciliationRow::query()->create([
            'reconciliation_period_id' => $period->id,
            'machine_id' => $machine->id,
            'machine_assignment_id' => $assignment->id,
            'project_id' => $project->id,
            'command_center_id' => $commandCenter->id,
            'work_date' => '2026-09-05',
            'segment_start' => '00:00:00',
            'segment_end' => '23:59:59',
            'status' => 'DRAFT',
        ]);

        return [$period, $row];
    }

    private function photos(ReconciliationRow $row, array $times): \Illuminate\Support\Collection
    {
        return collect($times)->map(fn (string $time) => $this->photo($row, $time));
    }

    private function photo(ReconciliationRow $row, string $time, ?string $fingerprint = null): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'canonical-downstream',
            'message_id' => (string) Str::uuid(),
            'sender_id' => 'sender-1',
            'sent_at' => '2026-09-05 '.$time.':00',
            'received_at' => now(),
            'status' => 'STORED',
        ]);
        $path = 'test/'.Str::uuid().'.jpg';
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
        $nearDuplicateIds = $fingerprint === null ? [] : OcrJob::query()
            ->where('machine_id', $row->machine_id)
            ->whereDate('extracted_date', '2026-09-05')
            ->get()
            ->filter(fn (OcrJob $candidate) => data_get($candidate->daily_metadata, 'image_fingerprint') === $fingerprint)
            ->modelKeys();
        $job = OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'machine_id' => $row->machine_id,
            'asset_code' => $row->machine->asset_code,
            'document_type' => 'DAILY_TIMEMARK',
            'extracted_date' => '2026-09-05',
            'extracted_time' => $time.':00',
            'status' => 'COMPLETED',
            'review_status' => 'AUTO_APPROVED',
            'confidence' => 0.99,
            'daily_metadata' => [
                'image_fingerprint' => $fingerprint,
                'near_duplicate_ids' => $nearDuplicateIds,
            ],
            'attempts' => 1,
        ])->fresh();
        app(DailyPhotoCaseService::class)->materialize($job);

        return $job->fresh(['dailyPhotoCase', 'dailyPhotoCaseEvidence']);
    }
}
