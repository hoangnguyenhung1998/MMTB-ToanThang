<?php
namespace Tests\Feature;
use App\Mail\MachineIntakeBchMail;
use App\Models\MachineIntakeCase;
use App\Models\User;
use App\Services\MachineIntakeBchService;
use App\Services\MachineSpecificationNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class MachineIntakeBchTest extends TestCase
{
 use RefreshDatabase;
 public function test_dx60_wheel_excavator_normalizes_to_capacity_55():void{$r=app(MachineSpecificationNormalizer::class)->normalize(['machine_type'=>'Máy đào bánh lốp','model_name'=>'Doosan DX60']);$this->assertSame('Máy xúc bánh lốp',$r['machine_type']);$this->assertSame(55,$r['capacity_class']);$this->assertSame('Doosan',$r['brand']);}
 public function test_vehicle_identity_combines_brand_and_plate():void{$n=app(MachineSpecificationNormalizer::class);$r=$n->normalize(['machine_type'=>'Xe ben 3 chân','brand'=>'HOWO','plate_no'=>'15K-01234']);$this->assertSame('Xe ô tô 3 chân',$n->displayType($r));$this->assertSame('HOWO · 15K-01234',$n->displayIdentity($r));}
 public function test_confirmed_case_generates_excel_and_sends_only_after_confirmation():void
 {
  config(['mail.default'=>'smtp']);Storage::fake('public');Mail::fake();$u=User::factory()->create();$c=MachineIntakeCase::create(['reference'=>'TN-2026-000099','status'=>'CONFIRMED','company'=>'VINALPHA','chassis_no'=>'FRAME99','engine_no'=>'ENGINE99','machine_type'=>'Máy xúc bánh lốp','brand'=>'Doosan','model_name'=>'DX55','capacity_class'=>55,'manufacture_year'=>2020,'confirmed_at'=>now(),'confirmed_by'=>$u->id]);
  $s=app(MachineIntakeBchService::class);$s->prepare($c,['to'=>'bch@example.com','subject'=>'Cấp mã FRAME99','body'=>'Đề nghị cấp mã']);Storage::disk('public')->assertExists($c->fresh()->bch_package_path);Mail::assertNothingSent();$s->send($c->fresh(),$u);Mail::assertSent(MachineIntakeBchMail::class);$this->assertSame('WAIT_ASSET_CODE',$c->fresh()->status);
 }
}
