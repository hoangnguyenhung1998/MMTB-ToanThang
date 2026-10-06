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
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
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
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 0], app(ReconciliationLinkRepairService::class)->repair($period, null));
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
        $result = app(ReconciliationLinkRepairService::class)->repair($period, null);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        fwrite(STDERR, sprintf("\nRepair benchmark: 2400 rows, 1200 stale; %.2f ms; %d queries; %d row models\n", $elapsed, $queries, $hydrated));
        $this->assertSame(['repaired' => 0, 'removed' => 1200, 'unresolved' => 0], $result);
        $this->assertSame(1200, $period->rows()->count());
        $this->assertSame(1200, ActivityLog::where('event', 'reconciliation.stale_row_removed')->count());
        $this->assertLessThan(100, $queries);
        $this->assertSame(0, $hydrated);
        $this->assertSame(['repaired' => 0, 'removed' => 0, 'unresolved' => 0], app(ReconciliationLinkRepairService::class)->repair($period, null));
    }
}
