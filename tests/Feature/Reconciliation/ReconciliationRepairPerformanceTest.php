<?php

namespace Tests\Feature\Reconciliation;

use App\Models\ActivityLog;
use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\Project;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use App\Services\Reconciliation\ReconciliationPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationRepairPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_content_reassignment_uses_batched_queries_and_preserves_payload(): void
    {
        $project = Project::create(['name' => 'Content benchmark']);
        $bch = CommandCenter::create(['name' => 'Content benchmark']);
        $period = app(ReconciliationPeriodService::class)->ensureMonthly('2026-09');
        $seed = [];
        foreach (range(1, 40) as $number) {
            $machine = Machine::create(['asset_code' => 'CONTENT-'.$number, 'chassis_no' => 'CONTENT-'.$number, 'company' => 'SGC', 'status' => 'ACTIVE']);
            $base = ['machine_id' => $machine->id, 'project_id' => $project->id, 'command_center_id' => $bch->id];
            $old = MachineAssignment::create($base + ['time_in' => '2026-08-01', 'time_out' => '2026-08-31 23:59:59']);
            MachineAssignment::create($base + ['time_in' => '2026-09-01']);
            foreach (range(1, 30) as $day) {
                $seed[] = $base + ['reconciliation_period_id' => $period->id, 'machine_assignment_id' => $old->id,
                    'work_date' => sprintf('2026-09-%02d', $day), 'segment_start' => '00:00:00', 'segment_end' => '23:59:59',
                    'status' => 'DRAFT', 'regular_minutes' => 321, 'work_content' => 'OCR content', 'daily_ocr_job_ids' => '[12,13]'];
            }
        }
        foreach (array_chunk($seed, 100) as $chunk) {
            DB::table('reconciliation_rows')->insert($chunk);
        }
        $hydrated = 0;
        \App\Models\ReconciliationRow::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = hrtime(true);
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        fwrite(STDERR, sprintf("\nContent reassignment benchmark: 1200 rows; %.2f ms; %d queries; %d row models\n", $elapsed, count($queries), $hydrated));
        $this->assertSame(1200, $result['repaired']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertLessThan(100, count($queries));
        $this->assertSame(0, $hydrated);
        $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query['query'], 'from "machine_assignments"')));
        $this->assertSame(1200, $period->rows()->where('regular_minutes', 321)->where('work_content', 'OCR content')->where('daily_ocr_job_ids', '[12,13]')->count());
        $this->assertSame(1200, ActivityLog::where('event', 'reconciliation.links_repaired')->count());
        $this->assertSame(0, app(ReconciliationLinkRepairService::class)->repair($period, null)['repaired']);
    }

    public function test_large_canonical_relink_and_empty_target_merge_use_bounded_queries(): void
    {
        $project = Project::create(['name' => 'Canonical benchmark']);
        $bch = CommandCenter::create(['name' => 'Canonical benchmark']);
        $period = app(ReconciliationPeriodService::class)->ensureMonthly('2026-09');
        $seed = array_fill_keys(['daily_photo_cases', 'zalo_messages', 'zalo_attachments', 'ocr_jobs', 'daily_photo_case_evidence', 'daily_photo_intervals', 'reconciliation_rows'], []);
        $n = 0;
        foreach (range(1, 40) as $number) {
            $machine = Machine::create(['asset_code' => 'CANON-'.$number, 'chassis_no' => 'CANON-'.$number, 'company' => 'SGC', 'status' => 'ACTIVE']);
            $base = ['machine_id' => $machine->id, 'project_id' => $project->id, 'command_center_id' => $bch->id];
            $old = MachineAssignment::create($base + ['time_in' => '2026-08-01', 'time_out' => '2026-08-31 23:59:59']);
            $target = MachineAssignment::create($base + ['time_in' => '2026-09-01']);
            foreach (range(1, 30) as $day) {
                $id = ++$n;
                $date = sprintf('2026-09-%02d', $day);
                $seed['daily_photo_cases'][] = ['id' => $id, 'machine_id' => $machine->id, 'machine_assignment_id' => $old->id,
                    'work_date' => $date, 'scope_key' => 'assignment:'.$old->id.'|date:'.$date, 'status' => 'READY', 'source_version' => 'v1'];
                foreach (['07:30:00', '11:00:00'] as $i => $time) {
                    $evidenceId = $id * 2 + $i;
                    $seed['zalo_messages'][] = ['id' => $evidenceId, 'group_id' => 'benchmark', 'message_id' => 'benchmark-'.$evidenceId,
                        'sender_id' => 'benchmark', 'sent_at' => $date.' '.$time, 'received_at' => $date.' '.$time, 'status' => 'STORED'];
                    $seed['zalo_attachments'][] = ['id' => $evidenceId, 'zalo_message_id' => $evidenceId, 'attachment_index' => 0,
                        'storage_disk' => 'local', 'storage_path' => 'benchmark/'.$evidenceId.'.jpg', 'sha256' => hash('sha256', (string) $evidenceId),
                        'mime_type' => 'image/jpeg', 'byte_size' => 10, 'status' => 'STORED'];
                    $seed['ocr_jobs'][] = ['id' => $evidenceId, 'zalo_attachment_id' => $evidenceId, 'document_type' => 'DAILY_TIMEMARK',
                        'status' => 'COMPLETED', 'machine_id' => $machine->id, 'daily_photo_case_id' => $id,
                        'daily_metadata' => json_encode(['case_materialization' => ['machine_assignment_id' => $old->id], 'content' => 'keep'])];
                    $seed['daily_photo_case_evidence'][] = ['id' => $evidenceId, 'daily_photo_case_id' => $id, 'ocr_job_id' => $evidenceId,
                        'capture_datetime' => $date.' '.$time, 'pairing_state' => 'PAIRED'];
                }
                $seed['daily_photo_intervals'][] = ['id' => $id, 'daily_photo_case_id' => $id, 'sequence' => 1,
                    'start_evidence_id' => $id * 2, 'end_evidence_id' => $id * 2 + 1, 'raw_start_at' => $date.' 07:30:00',
                    'raw_end_at' => $date.' 11:00:00', 'raw_start_time' => '07:30:00', 'raw_end_time' => '11:00:00', 'pairing_policy_version' => 'v1'];
                $common = $base + ['reconciliation_period_id' => $period->id, 'work_date' => $date,
                    'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT'];
                $seed['reconciliation_rows'][] = $common + ['id' => $id * 2, 'machine_assignment_id' => $old->id,
                    'regular_minutes' => 210, 'work_content' => 'HUMAN', 'daily_intervals' => json_encode([['canonical_interval_id' => $id]])];
                $seed['reconciliation_rows'][] = $common + ['id' => $id * 2 + 1, 'machine_assignment_id' => $target->id,
                    'regular_minutes' => 0, 'work_content' => null, 'daily_intervals' => null];
            }
        }
        foreach ($seed as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        foreach (['daily_photo_case_evidence', 'daily_photo_intervals', 'zalo_attachments', 'zalo_messages'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $hydrated = 0;
        \App\Models\ReconciliationRow::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        \App\Models\DailyPhotoCase::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        \App\Models\OcrJob::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = hrtime(true);
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        fwrite(STDERR, sprintf("\nCanonical merge benchmark: 2400 rows, 1200 cases, 2400 photos; %.2f ms; %d queries; %d models\n", $elapsed, count($queries), $hydrated));
        $this->assertSame(1200, $result['repaired']);
        $this->assertSame(1200, $result['removed']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertLessThan(200, count($queries));
        $this->assertSame(0, $hydrated);
        $this->assertSame(1200, DB::table('reconciliation_rows')->count());
        $this->assertSame(1200, DB::table('reconciliation_rows')->where('work_content', 'HUMAN')->where('regular_minutes', 210)->count());
        $this->assertSame(1200, DB::table('daily_photo_cases')->count());
        $this->assertSame(1200, ActivityLog::where('event', 'reconciliation.canonical_relinked')->count());
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $logs = ActivityLog::count();
        $again = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(0, $again['repaired']);
        $this->assertSame(0, $again['removed']);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_large_canonical_unassigned_normalization_uses_bounded_queries_and_preserves_evidence(): void
    {
        $project = Project::create(['name' => 'Canonical benchmark']);
        $bch = CommandCenter::create(['name' => 'Canonical benchmark']);
        $period = app(ReconciliationPeriodService::class)->ensureMonthly('2026-09');
        $seed = array_fill_keys(['daily_photo_cases', 'zalo_messages', 'zalo_attachments', 'ocr_jobs', 'daily_photo_case_evidence', 'daily_photo_intervals', 'reconciliation_rows'], []);
        $n = 0;
        foreach (range(1, 40) as $number) {
            $machine = Machine::create(['asset_code' => 'CANON-'.$number, 'chassis_no' => 'CANON-'.$number, 'company' => 'SGC', 'status' => 'ACTIVE']);
            $base = ['machine_id' => $machine->id, 'project_id' => $project->id, 'command_center_id' => $bch->id];
            $old = MachineAssignment::create($base + ['time_in' => '2026-08-01', 'time_out' => '2026-08-31 23:59:59']);
            $target = MachineAssignment::create($base + ['time_in' => '2026-10-13']);
            foreach (range(1, 30) as $day) {
                $id = ++$n;
                $date = sprintf('2026-09-%02d', $day);
                $seed['daily_photo_cases'][] = ['id' => $id, 'machine_id' => $machine->id, 'machine_assignment_id' => $old->id,
                    'work_date' => $date, 'scope_key' => 'assignment:'.$old->id.'|date:'.$date, 'status' => 'READY', 'source_version' => 'v1'];
                foreach (['07:30:00', '11:00:00'] as $i => $time) {
                    $evidenceId = $id * 2 + $i;
                    $seed['zalo_messages'][] = ['id' => $evidenceId, 'group_id' => 'benchmark', 'message_id' => 'benchmark-'.$evidenceId,
                        'sender_id' => 'benchmark', 'sent_at' => $date.' '.$time, 'received_at' => $date.' '.$time, 'status' => 'STORED'];
                    $seed['zalo_attachments'][] = ['id' => $evidenceId, 'zalo_message_id' => $evidenceId, 'attachment_index' => 0,
                        'storage_disk' => 'local', 'storage_path' => 'benchmark/'.$evidenceId.'.jpg', 'sha256' => hash('sha256', (string) $evidenceId),
                        'mime_type' => 'image/jpeg', 'byte_size' => 10, 'status' => 'STORED'];
                    $seed['ocr_jobs'][] = ['id' => $evidenceId, 'zalo_attachment_id' => $evidenceId, 'document_type' => 'DAILY_TIMEMARK',
                        'status' => 'COMPLETED', 'machine_id' => $machine->id, 'daily_photo_case_id' => $id,
                        'daily_metadata' => json_encode(['case_materialization' => ['machine_assignment_id' => $old->id], 'content' => 'keep'])];
                    $seed['daily_photo_case_evidence'][] = ['id' => $evidenceId, 'daily_photo_case_id' => $id, 'ocr_job_id' => $evidenceId,
                        'capture_datetime' => $date.' '.$time, 'pairing_state' => 'PAIRED'];
                }
                $seed['daily_photo_intervals'][] = ['id' => $id, 'daily_photo_case_id' => $id, 'sequence' => 1,
                    'start_evidence_id' => $id * 2, 'end_evidence_id' => $id * 2 + 1, 'raw_start_at' => $date.' 07:30:00',
                    'raw_end_at' => $date.' 11:00:00', 'raw_start_time' => '07:30:00', 'raw_end_time' => '11:00:00', 'pairing_policy_version' => 'v1'];
                $common = $base + ['reconciliation_period_id' => $period->id, 'work_date' => $date,
                    'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT'];
                $seed['reconciliation_rows'][] = $common + ['id' => $id * 2, 'machine_assignment_id' => $old->id,
                    'regular_minutes' => 210, 'work_content' => 'HUMAN', 'daily_intervals' => json_encode([['canonical_interval_id' => $id]])];
                $seed['reconciliation_rows'][] = $common + ['id' => $id * 2 + 1, 'machine_assignment_id' => $target->id,
                    'regular_minutes' => 0, 'work_content' => null, 'daily_intervals' => null];
            }
        }
        foreach ($seed as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        foreach (['daily_photo_case_evidence', 'daily_photo_intervals', 'zalo_attachments', 'zalo_messages'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $hydrated = 0;
        \App\Models\ReconciliationRow::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        \App\Models\DailyPhotoCase::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        \App\Models\OcrJob::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = hrtime(true);
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        fwrite(STDERR, sprintf("\nCanonical unassigned benchmark: 2400 rows, 1200 cases, 2400 photos; %.2f ms; %d queries; %d models\n", $elapsed, count($queries), $hydrated));
        $this->assertSame(0, $result['repaired']);
        $this->assertSame(1200, $result['normalized_unassigned']);
        $this->assertSame(1200, $result['removed']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertLessThan(200, count($queries));
        $this->assertSame(0, $hydrated);
        $this->assertSame(1200, DB::table('reconciliation_rows')->count());
        $this->assertSame(1200, DB::table('reconciliation_rows')->where('work_content', 'HUMAN')->where('regular_minutes', 210)->count());
        $this->assertSame(1200, DB::table('daily_photo_cases')->count());
        $this->assertSame(1200, ActivityLog::where('event', 'reconciliation.canonical_relinked')->count());
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $logs = ActivityLog::count();
        $again = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $this->assertSame(0, $again['normalized_unassigned']);
        $this->assertSame(0, $again['repaired']);
        $this->assertSame(0, $again['removed']);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_large_catalog_repair_batches_updates_and_audits_across_machine_batches(): void
    {
        $project = Project::create(['name' => 'Batch repair']);
        $bch = CommandCenter::create(['name' => 'Batch repair']);
        $period = app(ReconciliationPeriodService::class)->ensureMonthly('2026-09');
        $seed = [];
        for ($number = 1; $number <= 120; $number++) {
            $machine = Machine::create(['asset_code' => 'BATCH-'.$number, 'chassis_no' => 'BATCH-'.$number, 'company' => 'SGC', 'status' => 'ACTIVE']);
            $assignment = MachineAssignment::create(['machine_id' => $machine->id, 'project_id' => $project->id,
                'command_center_id' => $bch->id, 'time_in' => '2026-09-01']);
            for ($day = 1; $day <= 10; $day++) {
                $seed[] = ['reconciliation_period_id' => $period->id, 'machine_id' => $machine->id,
                    'machine_assignment_id' => $assignment->id, 'project_id' => $project->id, 'command_center_id' => null,
                    'work_date' => sprintf('2026-09-%02d', $day), 'segment_start' => '00:00:00', 'segment_end' => '23:59:59',
                    'status' => 'DRAFT', 'regular_minutes' => 321, 'daily_ocr_job_ids' => '[12,13]'];
            }
        }
        foreach (array_chunk($seed, 100) as $chunk) {
            DB::table('reconciliation_rows')->insert($chunk);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = hrtime(true);
        $result = array_intersect_key(app(ReconciliationLinkRepairService::class)->repair($period, null), array_flip(['repaired', 'removed', 'unresolved']));
        $elapsed = (hrtime(true) - $start) / 1e6;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        fwrite(STDERR, sprintf("\nCatalog benchmark: 1200 rows, 120 machines; %.2f ms; %d queries\n", $elapsed, count($queries)));
        $this->assertSame(['repaired' => 1200, 'removed' => 0, 'unresolved' => 0], $result);
        $this->assertLessThan(100, count($queries));
        $assignmentQueries = array_filter($queries, fn ($query) => str_contains($query['query'], 'machine_assignments'));
        $this->assertCount(2, $assignmentQueries);
        $this->assertSame(1200, $period->rows()->where('command_center_id', $bch->id)->where('regular_minutes', 321)->count());
        $this->assertSame(1200, ActivityLog::where('event', 'reconciliation.links_repaired')->count());
        $sample = $period->rows()->first();
        $this->assertSame([12, 13], $sample->daily_ocr_job_ids);
        $log = ActivityLog::where('event', 'reconciliation.links_repaired')->where('subject_id', $sample->id)->first();
        $this->assertSame(['command_center_id' => null], $log->properties['old']);
        $this->assertSame(['command_center_id' => $bch->id], $log->properties['new']);
        $logs = ActivityLog::count();
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 0], array_intersect_key(app(ReconciliationLinkRepairService::class)->repair($period, null), array_flip(['repaired', 'removed', 'unresolved'])));
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_large_stale_evidence_population_uses_bounded_queries_and_no_row_hydration(): void
    {
        $project = Project::create(['name' => 'Repair benchmark']);
        $bch = CommandCenter::create(['name' => 'Repair benchmark']);
        $period = app(ReconciliationPeriodService::class)->ensureMonthly('2026-09');
        $seed = [];
        for ($machineNumber = 1; $machineNumber <= 40; $machineNumber++) {
            $machine = Machine::create(['asset_code' => 'PERF-'.$machineNumber, 'chassis_no' => 'PERF-'.$machineNumber, 'company' => 'SGC', 'status' => 'ACTIVE']);
            $base = ['machine_id' => $machine->id, 'project_id' => $project->id, 'command_center_id' => $bch->id];
            $old = MachineAssignment::create($base + ['time_in' => '2026-08-01', 'time_out' => '2026-08-31 23:59:59']);
            $current = MachineAssignment::create($base + ['time_in' => '2026-09-01']);
            for ($day = 1; $day <= 30; $day++) {
                $common = $base + ['reconciliation_period_id' => $period->id, 'work_date' => sprintf('2026-09-%02d', $day),
                    'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT',
                    'daily_ocr_job_ids' => json_encode([$machineNumber * 100 + $day]), 'regular_minutes' => 300];
                $seed[] = $common + ['machine_assignment_id' => $old->id];
                $seed[] = $common + ['machine_assignment_id' => $current->id];
            }
        }
        foreach (array_chunk($seed, 100) as $chunk) {
            DB::table('reconciliation_rows')->insert($chunk);
        }
        $hydrated = 0;
        \App\Models\ReconciliationRow::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = hrtime(true);
        $result = array_intersect_key(app(ReconciliationLinkRepairService::class)->repair($period, null), array_flip(['repaired', 'removed', 'unresolved']));
        $elapsed = (hrtime(true) - $start) / 1e6;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        fwrite(STDERR, sprintf("\nRepair benchmark: 2400 rows, 1200 stale; %.2f ms; %d queries; %d row models\n", $elapsed, $queries, $hydrated));
        $this->assertSame(['repaired' => 0, 'removed' => 1200, 'unresolved' => 0], $result);
        $this->assertSame(1200, $period->rows()->count());
        $this->assertSame(1200, ActivityLog::where('event', 'reconciliation.stale_row_removed')->count());
        $this->assertLessThan(100, $queries);
        $this->assertSame(0, $hydrated);
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 0], array_intersect_key(app(ReconciliationLinkRepairService::class)->repair($period, null), array_flip(['repaired', 'removed', 'unresolved'])));
    }
}
