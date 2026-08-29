<?php

namespace Tests\Feature;

use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineHandoverCase;
use App\Models\MachineIntakeCase;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MachineHandoverOcrTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_ocr_review_and_confirmation_use_date_only_and_wait_activation(): void
    {
        Storage::fake('public'); Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        config(['ocr.worker_token' => 'worker-token', 'telegram.enabled' => true, 'telegram.bot_token' => 'bot', 'telegram.chat_id' => 'chat']);
        $user = User::factory()->create(); $project = Project::create(['name' => 'Hạ Long Xanh']); $bch = CommandCenter::create(['name' => 'BCH HLX']);
        $machine = Machine::create(['asset_code' => 'T-LU0040', 'company' => 'VINALPHA', 'machine_type' => 'Lu rung 12-14 tấn', 'chassis_no' => 'FRAME-40', 'engine_no' => 'ENGINE-40', 'status' => 'WAIT_HANDOVER']);
        MachineIntakeCase::create(['reference' => 'TN-2026-000040', 'machine_id' => $machine->id, 'project_id' => $project->id, 'status' => 'WAIT_HANDOVER']);

        $this->actingAs($user)->post(route('machine-handovers.store', $machine), ['documents' => [UploadedFile::fake()->image('handover.jpg')]])->assertRedirect();
        $case = MachineHandoverCase::firstOrFail(); $job = $case->documents()->firstOrFail()->ocrJob;
        $claim = $this->withToken('worker-token')->postJson(route('api.ocr.handovers.claim'), ['worker_id' => 'laptop'])->assertOk();
        $this->withToken('worker-token')->postJson(route('api.ocr.handovers.complete', $job), [
            'worker_id' => 'laptop', 'confidence' => .91, 'extraction' => ['asset_code' => 'T-LU0040', 'handover_date' => '2026-08-29', 'project_text' => 'Hạ Long Xanh'], 'review_flags' => [],
        ])->assertOk();

        $this->actingAs($user)->post(route('machine-handovers.confirm', $case), ['handover_date' => '2026-08-29', 'project_id' => $project->id, 'command_center_id' => $bch->id])->assertRedirect(route('machines.show', $machine));
        $this->assertDatabaseHas('machines', ['id' => $machine->id, 'status' => 'HANDED_OVER']);
        $this->assertDatabaseHas('machine_assignments', ['machine_id' => $machine->id, 'handover_date' => '2026-08-29']);
        $this->assertDatabaseHas('machine_events', ['machine_id' => $machine->id, 'event_date' => '2026-08-29', 'type' => 'HANDOVER']);
        $this->assertSame('HANDED_OVER', $case->fresh()->status);
    }

    public function test_confirm_cannot_change_project_selected_during_intake(): void
    {
        Storage::fake('public'); $user = User::factory()->create(); $expected = Project::create(['name' => 'Olympic']); $other = Project::create(['name' => 'Khác']); $bch = CommandCenter::create(['name' => 'BCH']);
        $machine = Machine::create(['asset_code' => 'VX-01', 'company' => 'VINCONS', 'chassis_no' => 'F1', 'engine_no' => 'E1', 'machine_type' => 'Máy xúc bánh xích', 'status' => 'WAIT_HANDOVER']);
        $intake = MachineIntakeCase::create(['reference' => 'TN-2026-1', 'machine_id' => $machine->id, 'project_id' => $expected->id, 'status' => 'WAIT_HANDOVER']);
        $case = MachineHandoverCase::create(['machine_id' => $machine->id, 'machine_intake_case_id' => $intake->id, 'project_id' => $expected->id, 'status' => 'REVIEW']);
        $case->documents()->create(['storage_disk' => 'public', 'storage_path' => 'proof.jpg', 'original_name' => 'proof.jpg', 'sha256' => str_repeat('a', 64)]);
        $this->actingAs($user)->post(route('machine-handovers.confirm', $case), ['handover_date' => '2026-08-29', 'project_id' => $other->id, 'command_center_id' => $bch->id])->assertSessionHasErrors('project_id');
        $this->assertSame('WAIT_HANDOVER', $machine->fresh()->status);
    }
}
