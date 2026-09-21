<?php

namespace Tests\Feature;

use App\Models\CommandCenter;
use App\Models\DailyPhotoCase;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Services\DailyPhotoCaseService;
use App\Services\DailyPhotoPairingService;
use App\Services\Reconciliation\DailyPhotoResyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyPhotoLargeVolumeReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocate_times_reconciles_more_than_one_thousand_canonical_evidence_in_bounded_batches(): void
    {
        config(['daily_photos.enabled' => true]);
        [$period, $manualRow, $partialCase] = $this->largeCanonicalFixture();

        $this->mock(DailyPhotoCaseService::class, fn ($mock) => $mock->shouldNotReceive('materialize'));
        $this->mock(DailyPhotoPairingService::class, fn ($mock) => $mock->shouldNotReceive('recomputeMany'));
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $startedAt = microtime(true);
        $result = app(DailyPhotoResyncService::class)->sync($period);
        $duration = microtime(true) - $startedAt;
        $firstReads = collect($queries)->filter(fn (string $sql) => str_starts_with(ltrim($sql), 'select'));

        $this->assertLessThan(30, $duration, "Large reconciliation took {$duration} seconds.");
        $this->assertLessThanOrEqual(130, $firstReads->count(), $firstReads->implode(PHP_EOL));
        $this->assertSame(499, $result['updated']);
        $this->assertSame(1, $result['protected']);
        $this->assertSame(1, $result['partial']);
        $this->assertDatabaseCount('daily_photo_case_evidence', 1001);
        $this->assertDatabaseCount('daily_photo_intervals', 500);
        $this->assertDatabaseCount('reconciliation_rows', 500);

        $manualRow->refresh();
        $this->assertSame(123, (int) $manualRow->regular_minutes);
        $this->assertSame('REVIEWED', $manualRow->status);
        $this->assertTrue($manualRow->has_evidence_changes);
        $partialRow = ReconciliationRow::query()
            ->where('machine_id', $partialCase->machine_id)
            ->where('machine_assignment_id', $partialCase->machine_assignment_id)
            ->whereDate('work_date', $partialCase->work_date)
            ->sole();
        $this->assertSame('DAILY_PARTIAL', $partialRow->evidence_status);
        $this->assertCount(3, $partialRow->daily_ocr_job_ids);
        $this->assertNull($partialRow->regular_afternoon_end);

        $queries = [];
        $second = app(DailyPhotoResyncService::class)->sync($period);
        $secondReads = collect($queries)->filter(fn (string $sql) => str_starts_with(ltrim($sql), 'select'));
        $secondWrites = collect($queries)->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete)\b/', $sql) === 1);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(1, $second['protected']);
        $this->assertLessThanOrEqual(130, $secondReads->count(), $secondReads->implode(PHP_EOL));
        $this->assertLessThanOrEqual(1, $secondWrites->count(), $secondWrites->implode(PHP_EOL));
        $this->assertDatabaseCount('daily_photo_case_evidence', 1001);
        $this->assertDatabaseCount('daily_photo_intervals', 500);
        $this->assertDatabaseCount('reconciliation_rows', 500);
    }

    public function test_failed_batch_rolls_back_cleanly_and_retry_resumes_idempotently(): void
    {
        config(['daily_photos.enabled' => true]);
        [$period, $manualRow] = $this->largeCanonicalFixture();
        $failOnce = true;
        $updates = 0;
        ReconciliationRow::updating(function () use (&$failOnce, &$updates): void {
            $updates++;
            if ($failOnce && $updates === 101) {
                $failOnce = false;
                throw new \RuntimeException('Simulated batch failure');
            }
        });

        try {
            app(DailyPhotoResyncService::class)->sync($period);
            $this->fail('The simulated batch failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated batch failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('daily_photo_case_evidence', 1001);
        $this->assertDatabaseCount('daily_photo_intervals', 500);
        $this->assertDatabaseCount('reconciliation_rows', 500);
        $this->assertSame(99, ReconciliationRow::query()->whereNotNull('evidence_signature')->count());
        $this->assertSame(123, (int) $manualRow->fresh()->regular_minutes);
        $this->assertSame('REVIEWED', $manualRow->fresh()->status);

        $retry = app(DailyPhotoResyncService::class)->sync($period);

        $this->assertSame(400, $retry['updated']);
        $this->assertSame(1, $retry['protected']);
        $this->assertSame(499, ReconciliationRow::query()->whereNotNull('evidence_signature')->count());
        $this->assertDatabaseCount('daily_photo_case_evidence', 1001);
        $this->assertDatabaseCount('daily_photo_intervals', 500);
        $this->assertDatabaseCount('reconciliation_rows', 500);
        $this->assertSame(123, (int) $manualRow->fresh()->regular_minutes);
    }

    private function largeCanonicalFixture(): array
    {
        $project = Project::query()->create(['name' => 'Large volume project']);
        $commandCenter = CommandCenter::query()->create(['name' => 'Large volume BCH']);
        $now = now();
        $machines = [];

        foreach (range(1, 50) as $number) {
            $assetCode = sprintf('LV-%03d', $number);
            $machines[] = [
                'asset_code' => $assetCode,
                'chassis_no' => 'CHASSIS-'.$assetCode,
                'company' => 'VINCONS',
                'status' => 'ACTIVE',
                'returned_to_app' => true,
                'gps_file_added' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertInChunks('machines', $machines);
        $machinesByCode = Machine::query()->whereLike('asset_code', 'LV-%')->get()->keyBy('asset_code');
        $assignments = [];
        foreach ($machinesByCode as $machine) {
            $assignments[] = [
                'machine_id' => $machine->id,
                'project_id' => $project->id,
                'command_center_id' => $commandCenter->id,
                'time_in' => '2026-09-01 00:00:00',
                'time_out' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertInChunks('machine_assignments', $assignments);
        $assignmentsByMachine = MachineAssignment::query()->whereIn('machine_id', $machinesByCode->modelKeys())
            ->get()->keyBy('machine_id');
        $period = ReconciliationPeriod::query()->create([
            'name' => 'Large volume September 2026',
            'type' => 'MONTHLY',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
            'status' => 'GENERATED',
        ]);

        $caseRows = [];
        foreach ($machinesByCode as $machine) {
            $assignment = $assignmentsByMachine->get($machine->id);
            foreach (range(1, 10) as $day) {
                $date = sprintf('2026-09-%02d', $day);
                $caseRows[] = [
                    'scope_key' => "assignment:{$assignment->id}|date:{$date}",
                    'machine_id' => $machine->id,
                    'machine_assignment_id' => $assignment->id,
                    'work_date' => $date,
                    'status' => DailyPhotoCase::STATUS_READY,
                    'source_version' => config('daily_photos.foundation_version'),
                    'pairing_policy_version' => config('daily_photos.pairing_policy_version'),
                    'pairing_computed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $this->insertInChunks('daily_photo_cases', $caseRows);
        $cases = DailyPhotoCase::query()->orderBy('id')->get();
        $partialCase = $cases->last();
        $partialCase->update(['status' => DailyPhotoCase::STATUS_COLLECTING]);

        $messageRows = [];
        $tokensByCase = [];
        foreach ($cases as $case) {
            $times = $case->is($partialCase) ? ['07:00:00', '11:00:00', '13:00:00'] : ['07:00:00', '11:00:00'];
            foreach ($times as $index => $time) {
                $token = "case-{$case->id}-{$index}";
                $tokensByCase[$case->id][] = $token;
                $messageRows[] = [
                    'group_id' => 'large-volume',
                    'message_id' => $token,
                    'sender_id' => 'large-volume-sender',
                    'sent_at' => $case->work_date->format('Y-m-d').' '.$time,
                    'received_at' => $case->work_date->format('Y-m-d').' '.$time,
                    'status' => 'STORED',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $this->insertInChunks('zalo_messages', $messageRows);
        $messages = DB::table('zalo_messages')->where('group_id', 'large-volume')->get()->keyBy('message_id');
        $attachmentRows = [];
        foreach ($messages as $token => $message) {
            $attachmentRows[] = [
                'zalo_message_id' => $message->id,
                'attachment_index' => 0,
                'original_name' => $token.'.jpg',
                'storage_disk' => 'local',
                'storage_path' => 'large-volume/'.$token.'.jpg',
                'sha256' => hash('sha256', $token),
                'mime_type' => 'image/jpeg',
                'byte_size' => 100,
                'status' => 'STORED',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertInChunks('zalo_attachments', $attachmentRows);
        $attachments = DB::table('zalo_attachments')->whereIn('zalo_message_id', $messages->pluck('id'))
            ->get()->keyBy('zalo_message_id');
        $jobRows = [];
        foreach ($cases as $case) {
            foreach ($tokensByCase[$case->id] as $token) {
                $message = $messages->get($token);
                $jobRows[] = [
                    'zalo_attachment_id' => $attachments->get($message->id)->id,
                    'machine_id' => $case->machine_id,
                    'status' => 'COMPLETED',
                    'document_type' => 'DAILY_TIMEMARK',
                    'review_status' => 'AUTO_APPROVED',
                    'attempts' => 1,
                    'extracted_date' => $case->work_date->format('Y-m-d'),
                    'extracted_time' => substr($message->sent_at, 11),
                    'asset_code' => $machinesByCode->firstWhere('id', $case->machine_id)->asset_code,
                    'confidence' => 0.99,
                    'daily_photo_case_id' => $case->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $this->insertInChunks('ocr_jobs', $jobRows);
        $jobsByAttachment = DB::table('ocr_jobs')->whereIn('zalo_attachment_id', $attachments->pluck('id'))
            ->get()->keyBy('zalo_attachment_id');
        $evidenceRows = [];
        foreach ($cases as $case) {
            foreach ($tokensByCase[$case->id] as $index => $token) {
                $message = $messages->get($token);
                $job = $jobsByAttachment->get($attachments->get($message->id)->id);
                $evidenceRows[] = [
                    'daily_photo_case_id' => $case->id,
                    'ocr_job_id' => $job->id,
                    'capture_datetime' => $message->sent_at,
                    'assignment_resolution_status' => 'MATCHED',
                    'pairing_state' => $index < 2 ? 'PAIRED' : 'UNMATCHED',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $this->insertInChunks('daily_photo_case_evidence', $evidenceRows);
        $evidenceByJob = DB::table('daily_photo_case_evidence')->get()->keyBy('ocr_job_id');
        $intervalRows = [];
        foreach ($cases as $case) {
            $tokens = $tokensByCase[$case->id];
            $startMessage = $messages->get($tokens[0]);
            $endMessage = $messages->get($tokens[1]);
            $startJob = $jobsByAttachment->get($attachments->get($startMessage->id)->id);
            $endJob = $jobsByAttachment->get($attachments->get($endMessage->id)->id);
            $intervalRows[] = [
                'daily_photo_case_id' => $case->id,
                'sequence' => 1,
                'start_evidence_id' => $evidenceByJob->get($startJob->id)->id,
                'end_evidence_id' => $evidenceByJob->get($endJob->id)->id,
                'raw_start_at' => $startMessage->sent_at,
                'raw_end_at' => $endMessage->sent_at,
                'raw_start_time' => substr($startMessage->sent_at, 11),
                'raw_end_time' => substr($endMessage->sent_at, 11),
                'status' => 'PAIRED',
                'pairing_policy_version' => config('daily_photos.pairing_policy_version'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertInChunks('daily_photo_intervals', $intervalRows);

        $firstCase = $cases->first();
        $firstAssignment = $assignmentsByMachine->get($firstCase->machine_id);
        $manualRow = ReconciliationRow::query()->create([
            'reconciliation_period_id' => $period->id,
            'machine_id' => $firstCase->machine_id,
            'machine_assignment_id' => $firstAssignment->id,
            'project_id' => $project->id,
            'command_center_id' => $commandCenter->id,
            'work_date' => $firstCase->work_date->format('Y-m-d'),
            'segment_start' => '00:00:00',
            'segment_end' => '23:59:59',
            'status' => 'REVIEWED',
            'regular_minutes' => 123,
            'manually_edited_at' => $now,
        ]);

        return [$period, $manualRow, $partialCase->fresh()];
    }

    private function insertInChunks(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 40) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
