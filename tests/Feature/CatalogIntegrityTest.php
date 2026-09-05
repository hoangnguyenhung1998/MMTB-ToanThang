<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\Project;
use App\Models\User;
use App\Services\CompanyCatalogService;
use App\Services\MachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_catalog_supports_sgc_and_renames_without_changing_machine_key(): void
    {
        $user = User::factory()->create();
        $company = Company::where('code', 'SGC')->firstOrFail();
        $machine = $this->machine('SGC');
        $this->actingAs($user)->put(route('companies.update', $company), ['name' => 'Công ty SGC mới', 'is_active' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('SGC', $machine->fresh()->company);
        $this->assertSame('Công ty SGC mới', $machine->fresh()->company_name);
        $this->get(route('machines.create'))->assertOk()->assertSee('Công ty SGC mới');
        $this->get(route('companies.index'))->assertOk()->assertSee('SGC');
    }

    public function test_used_company_cannot_be_deleted_and_inactive_company_can_be_kept_on_existing_machine(): void
    {
        $company = Company::where('code', 'SGC')->firstOrFail();
        $this->machine('SGC');
        $company->update(['is_active' => false]);
        $this->assertTrue(Validator::make(['company' => 'SGC'], ['company' => new \App\Rules\AvailableCompany()])->fails());
        $this->assertTrue(Validator::make(['company' => 'SGC'], ['company' => new \App\Rules\AvailableCompany('SGC')])->passes());
        $this->expectException(ValidationException::class);
        app(CompanyCatalogService::class)->delete($company);
    }

    public function test_unused_company_can_be_created_and_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('companies.store'), ['code' => 'NEWCO', 'name' => 'Công ty mới'])->assertSessionHasNoErrors();
        $company = Company::where('code', 'NEWCO')->firstOrFail();
        $this->delete(route('companies.destroy', $company))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('companies', ['code' => 'NEWCO']);
    }

    public function test_manual_machine_creation_accepts_catalog_company_and_rejects_unknown_code(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('machines.store'), ['asset_code' => 'SGC-TEST-1', 'chassis_no' => 'FRAME-SGC', 'company' => 'SGC'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('machines', ['asset_code' => 'SGC-TEST-1', 'company' => 'SGC']);
        $this->post(route('machines.store'), ['asset_code' => 'UNKNOWN-1', 'chassis_no' => 'FRAME-UNKNOWN', 'company' => 'NOT-REGISTERED'])->assertSessionHasErrors('company');
    }

    public function test_excel_import_uses_catalog_and_optional_default_company(): void
    {
        $this->actingAs(User::factory()->create());
        \Maatwebsite\Excel\Facades\Excel::shouldReceive('toArray')->once()->andReturn([[
            ['asset_code', 'chassis_no', 'engine_no', 'plate_no', 'machine_type', 'manufacture_year', 'company'],
            ['SGC-IMPORT-1', 'FRAME-IMPORT-1', '', '', '', '', 'SGC'],
            ['SGC-IMPORT-2', 'FRAME-IMPORT-2', '', '', '', '', ''],
        ]]);
        $file = \Illuminate\Http\UploadedFile::fake()->create('machines.xlsx', 1, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->post(route('machines.import'), ['file' => $file, 'company' => 'SGC'])->assertSessionHasNoErrors()->assertSessionMissing('import_errors');
        $this->assertDatabaseHas('machines', ['asset_code' => 'SGC-IMPORT-1', 'company' => 'SGC']);
        $this->assertDatabaseHas('machines', ['asset_code' => 'SGC-IMPORT-2', 'company' => 'SGC']);
    }

    public function test_renaming_project_and_bch_preserves_assignment_and_allows_transfer_with_numeric_ids(): void
    {
        $machine = $this->machine('VINCONS');
        $project = Project::create(['name' => 'Dự án trước']);
        $bch = CommandCenter::create(['name' => 'BCH trước']);
        $target = CommandCenter::create(['name' => 'BCH đích']);
        $assignment = MachineAssignment::create(['machine_id' => $machine->id, 'project_id' => (string) $project->id, 'command_center_id' => (string) $bch->id, 'time_in' => '2026-09-01 07:00:00']);
        $project->update(['name' => 'Dự án sau']);
        $bch->update(['name' => 'BCH sau']);
        $this->assertSame($bch->id, $assignment->fresh()->command_center_id);
        app(MachineService::class)->transferAssignment($machine->id, $project->id, $bch->id, $project->id, $target->id, '2026-09-05 12:00:00', '2026-09-05 13:00:00', null);
        $this->assertNotNull($assignment->fresh()->time_out);
        $this->assertSame($target->id, $machine->fresh()->currentAssignment->command_center_id);
    }

    public function test_bch_with_historical_assignment_cannot_be_deleted(): void
    {
        $project = Project::create(['name' => 'Dự án']);
        $bch = CommandCenter::create(['name' => 'BCH']);
        MachineAssignment::create(['machine_id' => $this->machine('VINCONS')->id, 'project_id' => $project->id, 'command_center_id' => $bch->id, 'time_in' => '2026-09-01', 'time_out' => '2026-09-02']);
        $this->expectException(ValidationException::class);
        $bch->delete();
    }

    public function test_project_with_assignment_cannot_be_deleted(): void
    {
        $project = Project::create(['name' => 'Dự án']);
        MachineAssignment::create(['machine_id' => $this->machine('VINCONS')->id, 'project_id' => $project->id, 'time_in' => '2026-09-01']);
        $this->expectException(ValidationException::class);
        $project->delete();
    }

    private function machine(string $company): Machine
    {
        $id = uniqid();
        return Machine::create(['asset_code' => 'TEST-'.$id, 'company' => $company, 'chassis_no' => $id, 'status' => 'ACTIVE']);
    }
}
