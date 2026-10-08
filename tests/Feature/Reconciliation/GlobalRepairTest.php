<?php

namespace Tests\Feature\Reconciliation;

use App\Models\ActivityLog;
use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRepairRun;
use App\Models\User;
use App\Services\Reconciliation\GlobalReconciliationRepairService;
use App\Services\Reconciliation\ReconciliationLinkRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class GlobalRepairTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $month = '2026-10'): array
    {
        $machine = Machine::create(['asset_code' => 'GLOBAL-'.$month, 'chassis_no' => 'GLOBAL-'.$month, 'company' => 'SGC', 'status' => 'ACTIVE']);
        $project = Project::create(['name' => 'Global']);
        $a = CommandCenter::create(['name' => 'A-'.$month]);
        $b = CommandCenter::create(['name' => 'B-'.$month]);
        $old = MachineAssignment::create(['machine_id' => $machine->id, 'project_id' => $project->id,
            'command_center_id' => $a->id, 'time_in' => '2026-08-01', 'time_out' => '2026-08-31 23:59:59']);
        $owner = MachineAssignment::create(['machine_id' => $machine->id, 'project_id' => $project->id,
            'command_center_id' => $b->id, 'time_in' => '2026-09-01']);
        $period = ReconciliationPeriod::create(['name' => $month, 'type' => 'MONTHLY', 'date_from' => $month.'-01',
            'date_to' => $month.'-28', 'status' => 'GENERATED']);
        $row = $period->rows()->create(['machine_id' => $machine->id, 'machine_assignment_id' => $old->id,
            'project_id' => $project->id, 'command_center_id' => $a->id, 'work_date' => $month.'-02',
            'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT', 'regular_minutes' => 210, 'work_content' => 'Keep']);

        return [$period, $row, $owner];
    }

    public function test_preview_is_select_only_including_http_and_ignores_pagination_filters(): void
    {
        [$period] = $this->fixture();
        for ($i = 0; $i < 24; $i++) {
            ReconciliationPeriod::create(['name' => 'Other '.$i, 'type' => 'WEEKLY', 'date_from' => '2026-09-01', 'date_to' => '2026-09-07', 'status' => 'DRAFT']);
        }
        $user = User::factory()->create();
        $this->actingAs($user);
        $before = DB::table('reconciliation_rows')->get()->toJson();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('reconciliation-periods.repair-all.preview', ['page' => 2, 'status' => 'CONFIRMED', 'type' => 'WEEKLY']));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $response->assertOk()->assertSee('25 kỳ')->assertSee('Áp dụng các kỳ đã xem trước');
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', ltrim($query['query']));
        }
        $preview = json_decode(Crypt::decryptString($response->viewData('token')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(25, $preview['periods']);
        $this->assertSame(1, $preview['periods'][$period->id]['plan']['repaired']);
        $this->assertSame($before, DB::table('reconciliation_rows')->get()->toJson());
        $this->assertSame(0, ReconciliationRepairRun::count());
        $this->get(route('reconciliation-periods.index'))->assertOk()->assertSee('Sửa liên kết tất cả kỳ');
    }

    public function test_apply_is_durable_deduplicated_and_matches_shared_plan(): void
    {
        [$period, $row, $owner] = $this->fixture();
        $user = User::factory()->create();
        $service = app(GlobalReconciliationRepairService::class);
        $preview = $service->preview($user);
        $token = Crypt::encryptString(json_encode($preview, JSON_THROW_ON_ERROR));
        $this->actingAs($user)->post(route('reconciliation-periods.repair-all.store'), ['preview_token' => $token])->assertRedirect();
        $this->post(route('reconciliation-periods.repair-all.store'), ['preview_token' => $token])->assertRedirect();
        $this->assertSame(1, ReconciliationRepairRun::count());
        $this->assertNotSame($owner->id, $row->fresh()->machine_assignment_id);
        $this->artisan('reconciliation:process-repair-runs')->assertSuccessful();
        $run = ReconciliationRepairRun::sole();
        $this->assertSame('COMPLETED', $run->status);
        $this->assertSame($owner->id, $row->fresh()->machine_assignment_id);
        $this->assertSame(210, (int) $row->fresh()->regular_minutes);
        $this->assertSame($preview['periods'][$period->id]['plan']['repaired'], $run->periods[$period->id]['result']['repaired']);
        $log = ActivityLog::where('event', 'reconciliation.links_repaired')->sole();
        $this->assertSame($run->id, $log->properties['repair_run_id']);
        $logs = ActivityLog::count();
        $this->assertFalse($service->processNext());
        $this->assertSame($logs, ActivityLog::count());
        $this->get(route('reconciliation-periods.repair-all.show', $run))->assertOk()->assertSee('Hoàn tất');
    }

    public function test_one_global_active_run_and_stale_preview_cannot_overwrite_data(): void
    {
        [$period, $row] = $this->fixture();
        $user = User::factory()->create();
        $service = app(GlobalReconciliationRepairService::class);
        $run = $service->start($user, $service->preview($user));
        try {
            $service->start($user, $service->preview($user));
            $this->fail('Concurrent global run accepted');
        } catch (\DomainException $exception) {
            $this->assertSame('GLOBAL_REPAIR_ALREADY_RUNNING', $exception->getMessage());
        }
        $row->update(['regular_minutes' => 999]);
        $before = $row->fresh()->getAttributes();
        $service->processNext();
        $this->assertSame('STALE_PREVIEW', $run->fresh()->periods[$period->id]['reason']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(0, ActivityLog::where('event', 'reconciliation.links_repaired')->count());
    }

    public function test_locked_protected_and_revoked_authorization_are_reported(): void
    {
        [$locked, $lockedRow] = $this->fixture();
        [$protected, $protectedRow] = $this->fixture('2026-11');
        $locked->update(['status' => 'CONFIRMED']);
        $protectedRow->update(['reviewed_at' => now()]);
        $user = User::factory()->create();
        $service = app(GlobalReconciliationRepairService::class);
        $preview = $service->preview($user);
        $this->assertSame('PROTECTED_PERIOD', $preview['periods'][$locked->id]['reason']);
        $this->assertSame(1, $preview['periods'][$protected->id]['plan']['unresolved']);
        $before = DB::table('reconciliation_rows')->get()->toJson();
        $run = $service->start($user, $preview);
        Gate::before(fn ($user, $ability) => $ability === 'repairLinks' ? false : null);
        $service->processNext();
        $this->assertSame('PROTECTED_OR_UNAUTHORIZED', $run->fresh()->periods[$protected->id]['reason']);
        $this->assertSame($before, DB::table('reconciliation_rows')->get()->toJson());
    }

    public function test_failed_period_rolls_back_and_later_period_completes_then_retry_is_safe(): void
    {
        [$first, $firstRow] = $this->fixture();
        [$second, $secondRow, $secondOwner] = $this->fixture('2026-11');
        $user = User::factory()->create();
        $service = app(GlobalReconciliationRepairService::class);
        $run = $service->start($user, $service->preview($user));
        $repair = new class($first->id) extends ReconciliationLinkRepairService
        {
            public function __construct(private int $failPeriod) {}

            public function repairExpected(ReconciliationPeriod $period, int $actor, string $expected, string $runId): array
            {
                $result = parent::repairExpected($period, $actor, $expected, $runId);
                if ($period->id === $this->failPeriod) {
                    throw new \RuntimeException('Simulated post-write failure');
                }

                return $result;
            }
        };
        $before = $firstRow->fresh()->getAttributes();
        $worker = new GlobalReconciliationRepairService($repair);
        $worker->processNext();
        $worker->processNext();
        $this->assertSame($before, $firstRow->fresh()->getAttributes());
        $this->assertSame($secondOwner->id, $secondRow->fresh()->machine_assignment_id);
        $this->assertSame('PARTIAL', $run->fresh()->status);
        $this->assertSame('FAILED', $run->fresh()->periods[$first->id]['status']);
        $retry = $service->start($user, $service->preview($user));
        $service->processNext();
        $service->processNext();
        $this->assertSame('COMPLETED', $retry->fresh()->status);
        $this->assertSame(1, $retry->fresh()->periods[$first->id]['result']['repaired']);
        $this->assertSame(0, $retry->fresh()->periods[$second->id]['result']['repaired']);
    }

    public function test_auth_actor_expiry_and_csrf_guards(): void
    {
        $this->fixture();
        $this->get(route('reconciliation-periods.repair-all.preview'))->assertRedirect(route('login'));
        $user = User::factory()->create();
        $service = app(GlobalReconciliationRepairService::class);
        $preview = $service->preview($user);
        $token = Crypt::encryptString(json_encode($preview, JSON_THROW_ON_ERROR));
        $this->actingAs(User::factory()->create())->post(route('reconciliation-periods.repair-all.store'), ['preview_token' => $token])->assertStatus(422);
        $this->actingAs($user)->post(route('reconciliation-periods.repair-all.store'), ['preview_token' => 'tampered'])->assertStatus(422);
        $preview['expires'] = now()->subMinute()->timestamp;
        $this->post(route('reconciliation-periods.repair-all.store'), ['preview_token' => Crypt::encryptString(json_encode($preview))])->assertStatus(422);
        $csrf = new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $this->app->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $csrf);
        $this->post(route('reconciliation-periods.repair-all.store'), ['preview_token' => $token])->assertStatus(419);
        Gate::before(fn ($user, $ability) => $ability === 'repairAll' ? false : null);
        $this->get(route('reconciliation-periods.repair-all.preview'))->assertForbidden();
    }
}
