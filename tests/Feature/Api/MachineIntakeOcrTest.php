<?php

namespace Tests\Feature\Api;

use App\Models\MachineIntakeCase;
use App\Models\User;
use App\Services\MachineIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MachineIntakeOcrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ocr.worker_token' => 'test-ocr-token']);
        Storage::fake('public');
    }

    public function test_new_intake_document_is_claimed_downloaded_and_extracted(): void
    {
        $case=$this->caseWithImage();
        $claim=$this->withToken('test-ocr-token')->postJson('/api/ocr/v1/intake/jobs/claim',['worker_id'=>'intake-worker'])->assertOk()
            ->assertJsonPath('job.case.reference',$case->reference)->assertJsonPath('job.document_type','CHASSIS_PLATE');
        $this->withToken('test-ocr-token')->get($claim->json('job.image_url'))->assertOk()->assertHeader('content-type','image/jpeg');
        $this->withToken('test-ocr-token')->postJson('/api/ocr/v1/intake/jobs/'.$claim->json('job.id').'/complete',[
            'worker_id'=>'intake-worker','confidence'=>.96,
            'extraction'=>['company'=>'VINALPHA','chassis_no'=>'KMTPC243PGC454794','engine_no'=>'6D107-26650512','machine_type'=>'Máy xúc đào bánh xích','model_name'=>'PC200LC-10','manufacture_year'=>2016],
            'review_flags'=>['VERIFY_AMBIGUOUS_CHASSIS_NO'],'raw_text'=>'Komatsu PC200LC-10',
        ])->assertOk()->assertJsonPath('job.status','EXCEPTION');
        $case->refresh();
        $this->assertSame('EXTRACTED',$case->status);
        $this->assertSame('KMTPC243PGC454794',$case->chassis_no);
        $this->assertSame('6D107-26650512',$case->engine_no);
        $this->assertContains('VERIFY_AMBIGUOUS_CHASSIS_NO',$case->review_flags);
    }

    public function test_claim_requires_worker_authentication(): void
    {
        $this->postJson('/api/ocr/v1/intake/jobs/claim',['worker_id'=>'worker'])->assertUnauthorized();
    }

    public function test_ocr_company_uses_catalog_and_unknown_candidate_does_not_break_completion(): void
    {
        foreach (['SGC', 'NEW-UNKNOWN'] as $company) {
            $case = $this->caseWithImage();
            $claim = $this->withToken('test-ocr-token')->postJson('/api/ocr/v1/intake/jobs/claim', ['worker_id' => 'intake-worker'])->assertOk();
            $this->withToken('test-ocr-token')->postJson('/api/ocr/v1/intake/jobs/'.$claim->json('job.id').'/complete', [
                'worker_id' => 'intake-worker', 'confidence' => .95,
                'extraction' => ['company' => $company, 'chassis_no' => 'FRAME123', 'engine_no' => 'ENGINE123'],
            ])->assertOk();
            $this->assertSame($company === 'SGC' ? 'SGC' : null, $case->fresh()->company);
            if ($company !== 'SGC') {
                $this->assertContains('UNKNOWN_COMPANY', $case->fresh()->review_flags);
                $this->assertSame($company, data_get($case->fresh()->extraction_summary, 'company.value'));
            }
        }
    }

    public function test_existing_pending_case_can_be_requeued_from_web(): void
    {
        $case=$this->caseWithImage();
        $job=$case->documents()->first()->ocrJob;
        $job->update(['status'=>'FAILED']);
        $this->actingAs(User::factory()->create())->post(route('machine-intakes.requeue',$case))->assertRedirect();
        $this->assertSame('PENDING',$job->fresh()->status);
    }

    private function caseWithImage(): MachineIntakeCase
    {
        $user=User::factory()->create();
        return app(MachineIntakeService::class)->createDraft(['document_type'=>'CHASSIS_PLATE'],[UploadedFile::fake()->image('khung.jpg')],$user);
    }
}
