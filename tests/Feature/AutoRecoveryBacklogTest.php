<?php

namespace Tests\Feature;

use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\OcrJob;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Models\ZaloSenderMachineMapping;
use App\Services\DailyPhotoBacklogService;
use App\Services\OcrJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoRecoveryBacklogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_photos.enabled' => true, 'ocr.max_attempts' => 3, 'ocr.minimum_confidence' => 0.8]);
        Storage::fake('local');
    }

    public function test_invalid_ocr_machine_falls_back_to_receipt_effective_sender_mapping(): void
    {
        $machine = $this->machine('T-XX0717');
        $job = $this->pendingJob('giang-ha');
        $this->mapping($job, $machine);

        $completed = $this->complete($job, ['asset_code' => '7X-XL13', 'date' => '2026-09-10', 'time' => '06:15:00']);

        $this->assertSame('COMPLETED', $completed->status);
        $this->assertSame($machine->id, $completed->machine_id);
        $this->assertSame('SENDER_MAPPING', $completed->machine_resolution_method);
        $this->assertSame('7X-XL13', $completed->observed_asset_code);
        $this->assertNull($completed->exceptions);
    }

    public function test_valid_image_machine_wins_without_changing_sender_mapping(): void
    {
        $mapped = $this->machine('VT-XL1137');
        $image = $this->machine('T-XL0345');
        $job = $this->pendingJob('sender-helper');
        $mapping = $this->mapping($job, $mapped);

        $completed = $this->complete($job, ['asset_code' => 't xl 0345', 'date' => '2026-09-10', 'time' => '06:15:00', 'confidence' => 0.1]);

        $this->assertSame('COMPLETED', $completed->status);
        $this->assertSame($image->id, $completed->machine_id);
        $this->assertSame('IMAGE_ASSET', $completed->machine_resolution_method);
        $this->assertNull($completed->exceptions);
        $this->assertSame($mapped->id, $mapping->fresh()->machine_id);
        $this->assertDatabaseCount('zalo_sender_machine_mappings', 1);
    }

    public function test_missing_machine_uses_mapping_but_missing_mapping_retries_then_fails_closed(): void
    {
        $machine = $this->machine('T-XX0717');
        $mappedJob = $this->pendingJob('mapped');
        $this->mapping($mappedJob, $machine);
        $mapped = $this->complete($mappedJob, ['date' => '2026-09-10', 'time' => '07:00:00']);
        $this->assertSame('COMPLETED', $mapped->status);
        $this->assertSame($machine->id, $mapped->machine_id);

        $unmappedJob = $this->pendingJob('unmapped');
        $retry = $this->complete($unmappedJob, ['date' => '2026-09-10', 'time' => '07:30:00', 'confidence' => 0.1]);
        $this->assertSame('RETRY', $retry->status);
        $this->assertSame('machine', $retry->ocr_retry_reason);

        $failed = $this->complete($retry, []);
        $this->assertSame('EXCEPTION', $failed->status);
        $this->assertContains('SENDER_MAPPING_MISSING', $failed->exceptions);
        $this->assertContains('OCR_RETRY_FAILED', $failed->exceptions);
        $this->assertNotContains('LOW_CONFIDENCE', $failed->exceptions);
    }

    public function test_targeted_time_retry_preserves_initial_fields_and_continues_automatically(): void
    {
        $machine = $this->machine('T-XX0717');
        $job = $this->pendingJob('time-retry');

        $retry = $this->complete($job, ['asset_code' => 'T.XX.0717', 'date' => '2026-09-10', 'confidence' => 0.1]);
        $this->assertSame('RETRY', $retry->status);
        $this->assertSame('time', $retry->ocr_retry_reason);

        $completed = $this->complete($retry, ['time' => '11:10:00', 'confidence' => 0.2]);
        $this->assertSame('COMPLETED', $completed->status);
        $this->assertSame($machine->id, $completed->machine_id);
        $this->assertSame('2026-09-10', $completed->extracted_date->format('Y-m-d'));
        $this->assertSame('OCR_RETRY', $completed->ocr_final_source);
        $this->assertSame('11:10:00', data_get($completed->daily_metadata, 'ocr_recovery.final_chosen_result.time'));
    }

    public function test_collision_remains_machine_ambiguous_even_when_sender_has_mapping(): void
    {
        $first = $this->machine('T-XX0717');
        $this->machine('T XX 0717');
        $job = $this->pendingJob('collision');
        $this->mapping($job, $first);

        $completed = $this->complete($job, ['asset_code' => 'T_XX_0717', 'date' => '2026-09-10', 'time' => '06:15:00']);

        $this->assertSame('EXCEPTION', $completed->status);
        $this->assertNull($completed->machine_id);
        $this->assertContains('MACHINE_AMBIGUOUS', $completed->exceptions);
        $this->assertSame(0, app(DailyPhotoBacklogService::class)->report(['sender_id' => 'collision'])['auto_recoverable']);
    }

    public function test_worker_candidate_conflicts_fail_closed_without_mapping_fallback_or_retry(): void
    {
        $mapped = $this->machine('T-XX0717');
        $job = $this->pendingJob('candidate-conflict');
        $this->mapping($job, $mapped);

        $completed = $this->complete($job, [
            'date' => '2026-09-21',
            'time' => '06:22:00',
            'candidate_metadata' => [
                'machine_candidates' => ['T-XX0717', 'T-XL0345'],
                'date_candidates' => ['2026-09-21', '2026-09-22'],
                'time_candidates' => ['06:22:00', '06:57:00'],
                'conflicts' => ['machine', 'date', 'time'],
            ],
        ]);

        $this->assertSame('EXCEPTION', $completed->status);
        $this->assertNull($completed->machine_id);
        $this->assertNull($completed->extracted_date);
        $this->assertNull($completed->extracted_time);
        $this->assertSame(0, $completed->ocr_retry_attempts);
        $this->assertContains('MACHINE_AMBIGUOUS', $completed->exceptions);
        $this->assertContains('CAPTURE_DATE_AMBIGUOUS', $completed->exceptions);
        $this->assertContains('CAPTURE_TIME_AMBIGUOUS', $completed->exceptions);
        $this->assertDatabaseCount('daily_photo_case_evidence', 0);
    }

    public function test_creating_mapping_does_not_recover_backlog_until_explicit_action_and_action_is_idempotent(): void
    {
        $machine = $this->machine('T-XX0717');
        $job = $this->exceptionJob('new-mapping', '7X-XL13');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('daily-photos.link'), ['sender_id' => 'new-mapping', 'machine_id' => $machine->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('EXCEPTION', $job->fresh()->status);
        $this->actingAs($user)->get(route('daily-photos.settings'))->assertOk()
            ->assertSee('1 ảnh đang chờ')->assertSee('XỬ LÝ ẢNH ĐANG CHỜ');

        $first = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'new-mapping']);
        $second = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'new-mapping']);
        $this->assertSame(1, $first['recovered']);
        $this->assertSame(0, $second['total']);
        $this->assertSame('COMPLETED', $job->fresh()->status);
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
    }

    public function test_first_mapping_can_recover_older_invalid_low_confidence_backlog_only_after_explicit_action(): void
    {
        $machine = $this->machine('T-XX0717');
        $job = $this->exceptionJob('first-mapping-backlog', '7X-XL13');
        $job->update([
            'confidence' => 0.5,
            'exceptions' => ['LOW_CONFIDENCE', 'UNKNOWN_ASSET_CODE'],
        ]);
        ZaloSenderMachineMapping::query()->create([
            'sender_id' => 'first-mapping-backlog',
            'active_sender_id' => 'first-mapping-backlog',
            'machine_id' => $machine->id,
            'valid_from' => '2026-09-11 09:32:00',
            'source' => 'MANUAL_CORRECTION',
        ]);

        $report = app(DailyPhotoBacklogService::class)->report(['sender_id' => 'first-mapping-backlog']);

        $this->assertSame('EXCEPTION', $job->fresh()->status);
        $this->assertSame(1, $report['mapped']);
        $this->assertSame(1, $report['auto_recoverable']);
        $this->assertSame(0, $report['manual']);
        $this->assertArrayNotHasKey('LOW_CONFIDENCE', $report['by_reason']);
        $this->assertSame($machine->id, $report['rows']->sole()['machine']->id);

        $result = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'first-mapping-backlog']);

        $this->assertSame(1, $result['recovered']);
        $this->assertSame('COMPLETED', $job->fresh()->status);
        $this->assertSame($machine->id, $job->fresh()->machine_id);
        $this->assertSame('SENDER_MAPPING', $job->fresh()->machine_resolution_method);
    }

    public function test_historical_low_confidence_only_is_not_a_reason_or_recovery_blocker(): void
    {
        $machine = $this->machine('T-XX0717');
        $job = $this->exceptionJob('obsolete-confidence', $machine->asset_code);
        $job->update(['confidence' => 0.1, 'exceptions' => ['LOW_CONFIDENCE']]);

        $report = app(DailyPhotoBacklogService::class)->report(['sender_id' => 'obsolete-confidence']);

        $this->assertSame(1, $report['auto_recoverable']);
        $this->assertSame([], $report['rows']->sole()['reasons']);
        $this->assertArrayNotHasKey('LOW_CONFIDENCE', $report['by_reason']);

        $result = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'obsolete-confidence']);

        $this->assertSame(1, $result['recovered']);
        $this->assertSame('COMPLETED', $job->fresh()->status);
        $this->assertSame($machine->id, $job->fresh()->machine_id);
    }

    public function test_first_mapping_old_backlog_with_missing_time_queues_only_one_targeted_retry(): void
    {
        $machine = $this->machine('T-XX0717');
        $job = $this->exceptionJob('first-mapping-retry', '7X-XL13');
        $job->update([
            'extracted_time' => null,
            'confidence' => 0.5,
            'exceptions' => ['LOW_CONFIDENCE', 'UNKNOWN_ASSET_CODE', 'MISSING_TIME'],
        ]);
        ZaloSenderMachineMapping::query()->create([
            'sender_id' => 'first-mapping-retry',
            'active_sender_id' => 'first-mapping-retry',
            'machine_id' => $machine->id,
            'valid_from' => '2026-09-11 09:32:00',
            'source' => 'MANUAL_CORRECTION',
        ]);

        $report = app(DailyPhotoBacklogService::class)->report(['sender_id' => 'first-mapping-retry']);
        $first = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'first-mapping-retry']);
        $second = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'first-mapping-retry']);

        $this->assertSame(1, $report['auto_recoverable']);
        $this->assertSame('RETRY', $report['rows']->sole()['action']);
        $this->assertSame(1, $first['queued_retry']);
        $this->assertSame(0, $second['total']);
        $this->assertSame('RETRY', $job->fresh()->status);
        $this->assertSame('time', $job->fresh()->ocr_retry_reason);
        $this->assertSame(1, $job->fresh()->ocr_retry_attempts);
    }

    public function test_mapping_changes_use_receipt_windows_and_never_retroactively_apply_current_mapping(): void
    {
        $machineA = $this->machine('T-XX0717');
        $machineB = $this->machine('T-XL0345');
        $jobs = collect([
            '2026-09-03 06:01:00' => $this->exceptionJob('mapping-history', null),
            '2026-09-08 06:01:00' => $this->exceptionJob('mapping-history', null),
            '2026-09-11 06:01:00' => $this->exceptionJob('mapping-history', null),
        ]);
        foreach ($jobs as $receivedAt => $job) {
            $job->attachment->message->update(['received_at' => $receivedAt]);
            $job->update(['extracted_date' => substr($receivedAt, 0, 10)]);
        }
        ZaloSenderMachineMapping::query()->create([
            'sender_id' => 'mapping-history',
            'machine_id' => $machineA->id,
            'valid_from' => '2026-09-01 00:00:00',
            'valid_to' => '2026-09-10 00:00:00',
            'source' => 'MANUAL_CORRECTION',
        ]);
        ZaloSenderMachineMapping::query()->create([
            'sender_id' => 'mapping-history',
            'active_sender_id' => 'mapping-history',
            'machine_id' => $machineB->id,
            'valid_from' => '2026-09-10 00:00:00',
            'source' => 'MANUAL_CORRECTION',
        ]);

        $report = app(DailyPhotoBacklogService::class)->report(['sender_id' => 'mapping-history']);
        $resolvedByJob = $report['rows']->keyBy(fn (array $row): int => $row['job']->id)
            ->map(fn (array $row): int => $row['machine']->id);

        $this->assertSame($machineA->id, $resolvedByJob[$jobs['2026-09-03 06:01:00']->id]);
        $this->assertSame($machineA->id, $resolvedByJob[$jobs['2026-09-08 06:01:00']->id]);
        $this->assertSame($machineB->id, $resolvedByJob[$jobs['2026-09-11 06:01:00']->id]);

        app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'mapping-history']);

        $this->assertSame($machineA->id, $jobs['2026-09-03 06:01:00']->fresh()->machine_id);
        $this->assertSame($machineA->id, $jobs['2026-09-08 06:01:00']->fresh()->machine_id);
        $this->assertSame($machineB->id, $jobs['2026-09-11 06:01:00']->fresh()->machine_id);
    }

    public function test_overlapping_mapping_history_is_not_auto_recoverable(): void
    {
        $machineA = $this->machine('T-XX0717');
        $machineB = $this->machine('T-XL0345');
        $job = $this->exceptionJob('overlapping-history', 'BAD-CODE');
        foreach ([
            [$machineA, '2026-09-01 00:00:00', '2026-09-20 00:00:00'],
            [$machineB, '2026-09-05 00:00:00', '2026-09-15 00:00:00'],
        ] as [$machine, $validFrom, $validTo]) {
            ZaloSenderMachineMapping::query()->create([
                'sender_id' => 'overlapping-history',
                'machine_id' => $machine->id,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'source' => 'MANUAL_CORRECTION',
            ]);
        }

        $report = app(DailyPhotoBacklogService::class)->report(['sender_id' => 'overlapping-history']);
        $result = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'overlapping-history']);

        $this->assertSame(0, $report['mapped']);
        $this->assertSame(0, $report['auto_recoverable']);
        $this->assertContains('MACHINE_AMBIGUOUS', $report['rows']->sole()['reasons']);
        $this->assertSame(1, $result['still_exception']);
        $this->assertNull($job->fresh()->machine_id);
    }

    public function test_repeating_same_recovery_does_not_duplicate_evidence_membership_case_or_reconciliation_row(): void
    {
        $machine = $this->machine('T-XX0717');
        $project = Project::query()->create(['name' => 'Dự án recovery']);
        $commandCenter = CommandCenter::query()->create(['name' => 'BCH recovery']);
        MachineAssignment::query()->create([
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'command_center_id' => $commandCenter->id,
            'time_in' => '2026-09-01 00:00:00',
        ]);
        ReconciliationPeriod::query()->create([
            'name' => 'Tháng 9/2026',
            'type' => 'MONTHLY',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
            'status' => 'GENERATED',
        ]);
        $job = $this->exceptionJob('idempotent-recovery', 'BAD-CODE');
        $this->mapping($job, $machine);

        $first = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'idempotent-recovery']);
        $caseId = $job->fresh()->daily_photo_case_id;
        $membershipId = DB::table('daily_photo_case_evidence')->sole()->id;
        $rowId = ReconciliationRow::query()->sole()->id;
        $second = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'idempotent-recovery']);

        $this->assertSame(1, $first['recovered']);
        $this->assertSame(0, $second['total']);
        $this->assertDatabaseCount('ocr_jobs', 1);
        $this->assertDatabaseCount('daily_photo_cases', 1);
        $this->assertDatabaseCount('daily_photo_case_evidence', 1);
        $this->assertDatabaseCount('reconciliation_rows', 1);
        $this->assertSame($caseId, $job->fresh()->daily_photo_case_id);
        $this->assertSame($membershipId, DB::table('daily_photo_case_evidence')->sole()->id);
        $this->assertSame($rowId, ReconciliationRow::query()->sole()->id);
    }

    public function test_recovery_never_overwrites_reviewed_or_human_data(): void
    {
        $machine = $this->machine('T-XX0717');
        $job = $this->exceptionJob('protected', null);
        $this->mapping($job, $machine);
        $job->update(['review_status' => 'CORRECTED', 'reviewed_at' => now(), 'machine_resolution_method' => 'HUMAN']);

        $result = app(DailyPhotoBacklogService::class)->recover(['sender_id' => 'protected']);

        $this->assertSame(1, $result['skipped_protected']);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
        $this->assertSame('HUMAN', $job->fresh()->machine_resolution_method);
    }

    public function test_stored_raw_reparse_recovers_five_production_patterns_without_external_ocr(): void
    {
        $patterns = [
            ['sender-a', 'T-XL0303', "[0deg/asset]\nT-XL 0303\n\n[0deg/full]\n06:22\n21 Sep,2026", '06:22:00'],
            ['sender-b', 'T-3C0172', "[0deg/full]\nT-3C 0172\n11:02\n09/21/2026", '11:02:00'],
            ['sender-c', 'T-3C0140', "[0deg/asset]\nT-3C0140\n\n[0deg/time_date]\n22:30\n\n[180deg/left_overlay]\n21 Tháng 9,2026", '22:30:00'],
            ['sender-e', 'SGC-T-3C0556', "[0deg/full]\nSGC-T-3C0556\n17:33\n21 Tháng 9,20265C", '17:33:00'],
        ];
        $jobs = collect();
        foreach ($patterns as [$sender, $assetCode, $raw, $time]) {
            $this->machine($assetCode);
            $job = $this->exceptionJob($sender, null);
            $job->update([
                'extracted_date' => null,
                'extracted_time' => null,
                'raw_text' => $raw,
                'exceptions' => ['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING'],
            ]);
            $jobs->push([$job, $assetCode, $time]);
        }

        $mappedMachine = $this->machine('SGC-T-3C0715');
        $mapped = $this->exceptionJob('sender-d', '3C0JI2');
        $mapped->update([
            'extracted_date' => null,
            'extracted_time' => null,
            'raw_text' => "[0deg/asset]\n3C0JI2\n\n[0deg/time_date]\n06-57\n21 Tháng 9,2026",
            'exceptions' => ['MACHINE_OCR_INVALID', 'CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING'],
        ]);
        $this->mapping($mapped, $mappedMachine);
        $jobs->push([$mapped, $mappedMachine->asset_code, '06:57:00']);

        $previewBefore = OcrJob::query()->orderBy('id')->get()->map->getAttributes();
        $preview = app(DailyPhotoBacklogService::class)->recoveryPreview();
        $this->artisan('ocr:daily-backlog-recover --dry-run')->assertSuccessful();

        $this->assertSame(5, $preview['recoverable_from_stored_ocr']);
        $this->assertSame(1, $preview['recoverable_from_mapping']);
        $this->assertEquals($previewBefore, OcrJob::query()->orderBy('id')->get()->map->getAttributes());

        $first = app(DailyPhotoBacklogService::class)->recover([]);
        $second = app(DailyPhotoBacklogService::class)->recover([]);

        $this->assertSame(5, $first['recovered']);
        $this->assertSame(0, $first['queued_retry']);
        $this->assertSame(0, $second['total']);
        foreach ($jobs as [$job, $assetCode, $time]) {
            $fresh = $job->fresh();
            $this->assertSame('COMPLETED', $fresh->status);
            $this->assertSame($assetCode, $fresh->machine->asset_code);
            $this->assertSame('2026-09-21', $fresh->extracted_date->format('Y-m-d'));
            $this->assertSame($time, $fresh->extracted_time);
            $this->assertNull($fresh->exceptions);
            $this->assertNotNull($fresh->dailyPhotoCaseEvidence);
        }
        $this->assertDatabaseCount('daily_photo_case_evidence', 5);
    }

    public function test_stored_retry_payload_supplements_missing_field_and_clears_stale_retry_reason(): void
    {
        $machine = $this->machine('T-XL0303');
        $job = $this->exceptionJob('stored-retry', $machine->asset_code);
        $job->update([
            'extracted_time' => null,
            'ocr_retry_attempts' => 1,
            'ocr_retry_reason' => 'time',
            'exceptions' => ['CAPTURE_TIME_MISSING', 'OCR_RETRY_FAILED'],
            'daily_metadata' => [
                'ocr_recovery' => [
                    'retry_extraction' => [
                        'raw_text' => "[0deg/time_date]\n06:22\n21 Sep,2026",
                    ],
                ],
            ],
        ]);

        $result = app(DailyPhotoBacklogService::class)->recover(['job' => $job->id]);

        $fresh = $job->fresh();
        $this->assertSame(1, $result['recovered']);
        $this->assertSame('06:22:00', $fresh->extracted_time);
        $this->assertSame('2026-09-10', $fresh->extracted_date->format('Y-m-d'));
        $this->assertNull($fresh->exceptions);
        $this->assertSame('STORED_REPARSE', $fresh->ocr_final_source);
        $this->assertSame('STORED_REPARSE', data_get($fresh->daily_metadata, 'ocr_recovery.source'));
    }

    public function test_conflicting_stored_candidates_remain_manual_and_do_not_queue_retry(): void
    {
        $machine = $this->machine('T-XL0303');
        $job = $this->exceptionJob('stored-conflict', $machine->asset_code);
        $job->update([
            'extracted_date' => null,
            'raw_text' => "[0deg/full]\n21 Sep,2026\n22 Sep,2026\n06:22",
            'exceptions' => ['CAPTURE_DATE_MISSING'],
        ]);

        $preview = app(DailyPhotoBacklogService::class)->recoveryPreview(['job' => $job->id]);
        $result = app(DailyPhotoBacklogService::class)->recover(['job' => $job->id]);

        $this->assertSame(1, $preview['ambiguous']);
        $this->assertSame(1, $preview['still_manual']);
        $this->assertSame(1, $result['still_exception']);
        $this->assertSame('EXCEPTION', $job->fresh()->status);
        $this->assertSame(0, $job->fresh()->ocr_retry_attempts);
    }

    public function test_backlog_report_is_read_only_and_classifies_sender_and_reason(): void
    {
        $machine = $this->machine('T-XX0717');
        $mapped = $this->exceptionJob('mapped-report', 'BAD-CODE');
        $unmapped = $this->exceptionJob('unmapped-report', null);
        $this->mapping($mapped, $machine);
        $before = OcrJob::query()->orderBy('id')->get()->map->getAttributes();

        $report = app(DailyPhotoBacklogService::class)->report();

        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['mapped']);
        $this->assertSame(1, $report['unmapped']);
        $this->assertArrayHasKey('MACHINE_OCR_INVALID', $report['by_reason']);
        $this->assertArrayHasKey('SENDER_MAPPING_MISSING', $report['by_reason']);
        $this->assertEquals($before, OcrJob::query()->orderBy('id')->get()->map->getAttributes());
        $this->artisan('ocr:daily-backlog-report')->assertSuccessful();
        $this->assertEquals($before, OcrJob::query()->orderBy('id')->get()->map->getAttributes());
    }

    public function test_report_batches_one_thousand_evidence_without_n_plus_one(): void
    {
        $now = now();
        $messages = $attachments = $jobs = [];
        foreach (range(1, 1000) as $index) {
            $messages[] = ['group_id' => 'batch', 'message_id' => "message-{$index}", 'sender_id' => 'batch-sender', 'sender_name' => 'Batch', 'sent_at' => $now, 'received_at' => $now, 'status' => 'STORED', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($messages, 100) as $chunk) {
            DB::table('zalo_messages')->insert($chunk);
        }
        foreach (DB::table('zalo_messages')->where('group_id', 'batch')->orderBy('id')->pluck('id') as $index => $messageId) {
            $attachments[] = ['zalo_message_id' => $messageId, 'attachment_index' => 0, 'original_name' => 'a.jpg', 'storage_disk' => 'local', 'storage_path' => "batch/{$index}.jpg", 'sha256' => hash('sha256', (string) $index), 'mime_type' => 'image/jpeg', 'byte_size' => 1, 'status' => 'STORED', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($attachments, 250) as $chunk) {
            DB::table('zalo_attachments')->insert($chunk);
        }
        foreach (DB::table('zalo_attachments')->where('storage_path', 'like', 'batch/%')->orderBy('id')->pluck('id') as $attachmentId) {
            $jobs[] = ['zalo_attachment_id' => $attachmentId, 'document_type' => 'DAILY_TIMEMARK', 'status' => 'EXCEPTION', 'review_status' => 'PENDING', 'attempts' => 1, 'confidence' => 0.99, 'exceptions' => json_encode(['MISSING_ASSET_CODE']), 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($jobs, 250) as $chunk) {
            DB::table('ocr_jobs')->insert($chunk);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $report = app(DailyPhotoBacklogService::class)->report();

        $this->assertSame(1000, $report['total']);
        $this->assertLessThanOrEqual(15, count($queries), implode(PHP_EOL, $queries));
    }

    private function machine(string $assetCode): Machine
    {
        return Machine::query()->create([
            'asset_code' => $assetCode,
            'chassis_no' => 'CHASSIS-'.Str::uuid(),
            'company' => 'VINCONS',
            'status' => 'ACTIVE',
        ]);
    }

    private function pendingJob(string $sender): OcrJob
    {
        return $this->job($sender, 'PENDING', null);
    }

    private function exceptionJob(string $sender, ?string $asset): OcrJob
    {
        return $this->job($sender, 'EXCEPTION', $asset, [
            'extracted_date' => '2026-09-10',
            'extracted_time' => '06:15:00',
            'confidence' => 0.99,
            'attempts' => 1,
            'exceptions' => [$asset ? 'UNKNOWN_ASSET_CODE' : 'MISSING_ASSET_CODE'],
        ]);
    }

    private function job(string $sender, string $status, ?string $asset, array $extra = []): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'auto-recovery',
            'message_id' => (string) Str::uuid(),
            'sender_id' => $sender,
            'sender_name' => $sender,
            'sent_at' => '2026-09-10 06:00:00',
            'received_at' => '2026-09-10 06:01:00',
            'status' => 'STORED',
        ]);
        $path = 'test/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'image');
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'image.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', $path),
            'mime_type' => 'image/jpeg',
            'byte_size' => 5,
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'document_type' => 'DAILY_TIMEMARK',
            'status' => $status,
            'review_status' => 'PENDING',
            'asset_code' => $asset,
            'observed_asset_code' => $asset,
            ...$extra,
        ])->fresh(['attachment.message']);
    }

    private function mapping(OcrJob $job, Machine $machine): ZaloSenderMachineMapping
    {
        return ZaloSenderMachineMapping::query()->create([
            'sender_id' => $job->attachment->message->sender_id,
            'active_sender_id' => $job->attachment->message->sender_id,
            'machine_id' => $machine->id,
            'valid_from' => $job->attachment->message->received_at->copy()->subMinute(),
            'source' => 'MANUAL_CORRECTION',
        ]);
    }

    private function complete(OcrJob $job, array $payload): OcrJob
    {
        $service = app(OcrJobService::class);
        if ($job->status !== 'PROCESSING') {
            $job = $service->claim('recovery-test', ['DAILY_TIMEMARK']);
        }

        return $service->complete($job, [
            'worker_id' => 'recovery-test',
            'attempt' => $job->attempts,
            'confidence' => 0.99,
            ...$payload,
        ]);
    }
}
