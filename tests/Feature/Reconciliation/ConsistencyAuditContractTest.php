<?php

namespace Tests\Feature\Reconciliation;

use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationConsistencyAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConsistencyAuditContractTest extends TestCase
{
    use RefreshDatabase;

    public static function scopes(): array
    {
        return ['nonempty default' => [1, false], 'nonempty detailed' => [1, true],
            'empty default' => [0, false], 'empty detailed' => [0, true],
            'multiple groups default' => [3, false], 'multiple groups detailed' => [3, true]];
    }

    #[DataProvider('scopes')]
    public function test_service_contract_and_select_only_queries(int $groups, bool $details): void
    {
        $period = $this->fixture($groups);
        $before = DB::table('reconciliation_rows')->get()->toJson();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $report = app(ReconciliationConsistencyAuditService::class)->audit($period, details: $details, release: '3d27a76');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', ltrim($query['query']));
        }
        $this->assertSame($before, DB::table('reconciliation_rows')->get()->toJson());
        $this->assertContract($report, $groups, $details);
    }

    #[DataProvider('scopes')]
    public function test_cli_details_is_opt_in(int $groups, bool $details): void
    {
        $period = $this->fixture($groups);
        $arguments = ['period' => $period->id, '--release' => '3d27a76'];
        if ($details) {
            $arguments['--details'] = true;
        }
        $this->assertSame(0, Artisan::call('reconciliation:consistency-audit', $arguments));
        $this->assertContract(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR), $groups, $details);
    }

    public function test_last_group_protection_does_not_control_details_or_other_dispositions(): void
    {
        $period = $this->fixture(3);
        $service = app(ReconciliationConsistencyAuditService::class);
        $before = $service->audit($period, details: true);
        $last = $period->rows()->orderByDesc('id')->first();
        $last->update(['reviewed_at' => now()]);
        $this->assertContract($service->audit($period), 3, false);
        $after = $service->audit($period, details: true);
        $this->assertSame(2, $after['schema_version']);
        $this->assertSame(array_slice($before['evidence']['rows'], 0, 2), array_slice($after['evidence']['rows'], 0, 2));
        $this->assertSame(['SAFE' => 2, 'HUMAN_REVIEW' => 1, 'UNSAFE' => 0], $after['evidence']['disposition_counts_by_row']);
    }

    private function assertContract(array $report, int $groups, bool $details): void
    {
        $this->assertSame($details ? 2 : 1, $report['schema_version']);
        $this->assertTrue($report['read_only']);
        $this->assertCount($groups, $report['groups']);
        $this->assertSame($groups, $report['summary']['rows']);
        if ($details) {
            $this->assertSame('3d27a76', $report['operator_reported_release']);
            $this->assertSame('SELECT_ONLY', $report['evidence']['mode']);
            $this->assertCount($groups, $report['evidence']['rows']);
            $this->assertSame(['SAFE' => $groups, 'HUMAN_REVIEW' => 0, 'UNSAFE' => 0], $report['evidence']['disposition_counts_by_row']);
        } else {
            $this->assertArrayNotHasKey('evidence', $report);
            $this->assertArrayNotHasKey('operator_reported_release', $report);
        }
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_WORK_TEXT', 'PRIVATE_RAW_OCR', 'PRIVATE_IMAGE_PATH', 'PRIVATE_COORDINATES', 'PRIVATE_SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        foreach ($report['groups'] as $group) {
            $this->assertCount(1, $group['rows']);
            $this->assertSame([], $group['pairs']);
        }
    }

    private function fixture(int $groups): ReconciliationPeriod
    {
        $period = ReconciliationPeriod::create(['name' => 'October audit', 'type' => 'MONTHLY',
            'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'status' => 'DRAFT']);
        if (! $groups) {
            return $period;
        }
        $machine = Machine::create(['asset_code' => 'AUDIT', 'chassis_no' => 'AUDIT', 'company' => 'SGC', 'status' => 'ACTIVE']);
        $project = Project::create(['name' => 'Audit']);
        $bch = CommandCenter::create(['name' => 'Audit']);
        $assignment = MachineAssignment::create(['machine_id' => $machine->id, 'project_id' => $project->id,
            'command_center_id' => $bch->id, 'time_in' => '2026-09-01 00:00:00']);
        for ($day = 1; $day <= $groups; $day++) {
            $period->rows()->create(['machine_id' => $machine->id, 'machine_assignment_id' => $assignment->id,
                'project_id' => $project->id, 'command_center_id' => $bch->id, 'work_date' => '2026-10-0'.$day,
                'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT',
                'work_content' => 'PRIVATE_WORK_TEXT',
                'daily_intervals' => [['notes' => 'PRIVATE_WORK_TEXT', 'raw_ocr' => 'PRIVATE_RAW_OCR',
                    'image_path' => 'PRIVATE_IMAGE_PATH', 'coordinates' => 'PRIVATE_COORDINATES', 'token' => 'PRIVATE_SECRET']]]);
        }

        return $period;
    }
}
