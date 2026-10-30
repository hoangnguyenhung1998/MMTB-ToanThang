<?php

namespace Tests\Feature;

use App\Exports\DossierWorkbook;
use App\Jobs\AnalyzeDossier;
use App\Models\DossierAiProposal;
use App\Models\DossierAiRun;
use App\Models\DossierBatch;
use App\Models\DossierSource;
use App\Models\DossierType;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\User;
use App\Services\Dossiers\Ai\DossierAiSchema;
use App\Services\Dossiers\Ai\DossierAiService;
use App\Services\Dossiers\Ai\DossierVision;
use App\Services\Dossiers\DossierExportService;
use App\Services\Dossiers\DossierStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DossierAiTest extends TestCase
{
    use RefreshDatabase;

    private array $responses = [];

    private User $user;

    private Machine $machine;

    private DossierSource $source;

    private DossierAiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::set('dossiers', Storage::fake('dossier-ai-'.getmypid(), ['throw' => true]));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        config(['dossiers.allowed_user_ids' => [$this->user->id], 'dossiers.storage_disk' => 'dossiers', 'dossiers.ai.enabled' => true, 'dossiers.ai.endpoint' => null, 'dossiers.ai.key' => null, 'dossiers.ai.model' => null]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 12:00:00', 'Asia/Ho_Chi_Minh')->utc());
        $this->machine = Machine::create(['asset_code' => 'T-AI01', 'company' => 'VINCONS', 'status' => 'ACTIVE', 'chassis_no' => 'FRAME01', 'dossier_group' => 'machine']);
        $batch = DossierBatch::create(['id' => (string) Str::uuid(), 'user_id' => $this->user->id]);
        $pdf = new \FPDF;
        foreach (range(1, 3) as $n) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 14);
            $pdf->Text(20, 30, 'AI MOCK PAGE '.$n);
        } $bytes = $pdf->Output('S');
        Storage::disk('dossiers')->put('sources/test.pdf', $bytes);
        $this->source = DossierSource::create(['batch_id' => $batch->id, 'machine_id' => $this->machine->id, 'user_id' => $this->user->id, 'original_name' => 'T-AI01.pdf', 'sha256' => hash('sha256', $bytes), 'path' => 'sources/test.pdf', 'bytes' => strlen($bytes), 'resolution' => 'RESOLVED', 'candidates' => [], 'status' => 'ready', 'page_count' => 3, 'progress' => 3, 'machine_confirmed' => true]);
        $object = app(\App\Services\Dossiers\DossierObjects::class)->store(new \Illuminate\Http\UploadedFile(Storage::disk('dossiers')->path($this->source->path), 'source.pdf', null, null, true), 'source');
        $object->update(['status' => 'published']);
        $this->source->update(['object_id' => $object->id]);
        $this->service = app(DossierAiService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function mockVision(array $responses): void
    {
        $this->responses = $responses;
        \Tests\Support\DossierTestWorker::worker();
    }

    private function page(): array
    {
        return ['text' => 'Mock specimen only, no private data', 'kind' => 'document', 'rotation' => 0, 'multiple_documents' => false, 'suspected_duplicate' => false, 'fragments' => [], 'fields' => [], 'issues' => []];
    }

    private function field(string $value, string $precision = 'day', int $page = 1): array
    {
        return ['value' => $value, 'precision' => $precision, 'page' => $page, 'quote' => 'Mock visible evidence '.$value, 'box' => [0, 0, 1, 1]];
    }

    private function doc(string $key = 'machine_inspection', array $pages = [1, 2, 3], array $fields = []): array
    {
        return ['title' => 'Mock paper', 'type_key' => $key, 'subject' => 'machine', 'pages' => $pages, 'fields' => $fields, 'expiry_absent' => false, 'permanent_explicit' => false, 'multiple_documents' => false, 'insured' => [], 'scope_text' => 'Mock scope', 'issues' => []];
    }

    private function group(array $docs): array
    {
        return ['documents' => $docs, 'unclassified_pages' => [], 'suggested_exclusions' => []];
    }

    private function drain(DossierAiRun $run, int $steps = 4): void
    {
        foreach (range(1, $steps) as $i) {
            DB::table('dossier_ai_control')->update(['next_call_at' => null]);
            DossierAiRun::whereKey($run->id)->update(['available_at' => null]);
            \App\Models\DossierWorkerJob::where('status', 'pending')->update(['available_at' => null]);
            $this->remoteStep($run);
        }
    }

    private function remoteStep(DossierAiRun $run): void
    {
        $jobs = app(\App\Services\Dossiers\DossierRemoteJobs::class);
        $worker = \Tests\Support\DossierTestWorker::worker();
        $job = $jobs->claim($worker);
        if (! $job) {
            return;
        }
        $claim = [$worker->id, $job->claim_token, $job->attempts];
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            $jobs->fail($job, $claim, 'ai_request_failed', true);

            return;
        }
        if ($job->kind === 'ai_page') {
            $image = \Illuminate\Http\UploadedFile::fake()->image('mock.png', 10, 10);
            $jobs->upload($job, 'image', $image, hash_file('sha256', $image->getRealPath()), $claim);
        }
        try {
            $jobs->complete($job, $claim, ['input_hash' => $job->input_hash, 'pipeline' => $job->pipeline, 'result' => ['response' => ['data' => $response, 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]], 'elapsed_ms' => 0, 'mock' => true]]);
        } catch (\Illuminate\Validation\ValidationException) {
            $jobs->fail($job, $claim, 'ai_schema_invalid', true);
        }
    }

    private function completed(array $docs): DossierAiRun
    {
        $this->mockVision([$this->page(), $this->page(), $this->page(), $this->group($docs)]);
        $r = $this->service->request($this->source, $this->user->id);
        $this->drain($r);

        return $r->fresh();
    }

    private function reviewData(DossierAiProposal $p, array $extra = []): array
    {
        return [...['source_revision' => $this->source->fresh()->revision, 'reviewed_evidence' => true, 'type_id' => $p->data['type_id'], 'subject' => 'machine', 'subject_id' => $this->machine->id, 'instance' => 'main', 'pages' => array_map(fn ($n) => ['page' => $n, 'rotation' => 0], $p->data['pages']), 'shared_pages' => false, 'effective_at' => '2026-10-01 00:00:00', 'expiry_precision' => 'unknown', 'reason' => 'Human checked mock evidence', 'confirmed' => true], ...$extra];
    }

    public function test_unconfigured_is_explicit_no_api_no_fake_success_manual_stays_available(): void
    {
        $this->post(route('dossiers.ai.request', $this->source))->assertRedirect();
        $r = DossierAiRun::firstOrFail();
        $this->assertSame('unconfigured', $r->status);
        Queue::assertNotPushed(AnalyzeDossier::class);
        $this->get(route('dossiers.source', $this->source))->assertOk()->assertSee('Chưa cấu hình AI')->assertSee('Phân loại giấy mới');
        $this->service->control($r, 'resume', null, $this->user->id);
        $this->assertSame('unconfigured', $r->fresh()->status);
        $this->assertDatabaseCount('dossier_versions', 0);
    }

    public function test_pipeline_groups_pages_saves_evidence_usage_without_confirming_and_replays_idempotently(): void
    {
        $r = $this->completed([$this->doc(fields: ['issued_on' => $this->field('2026-01-01'), 'certified_on' => $this->field('2026-10-29'), 'expiry' => $this->field('2027-01-01')])]);
        $this->assertSame('ready', $r->status);
        $this->assertSame(3, $r->pages()->where('status', 'ready')->count());
        $this->assertSame(1, $r->proposals()->count());
        $this->assertSame([1, 2, 3], $r->proposals()->first()->data['pages']);
        $this->assertDatabaseCount('dossier_versions', 0);
        $this->assertSame(10, $r->group_usage['prompt_tokens']);
        $again = $this->service->request($this->source, $this->user->id);
        $this->assertSame($r->id, $again->id);
        $this->service->step($r->id);
        $this->assertSame(1, $r->proposals()->count());
        $this->assertSame($this->source->sha256, hash_file('sha256', Storage::disk('dossiers')->path($this->source->path)));
        $this->get(route('dossiers.source', $this->source))->assertOk()->assertSee('Bằng chứng từng trường');
    }

    public function test_human_review_then_pdf_excel_use_same_confirmed_version_not_raw_ai(): void
    {
        $r = $this->completed([$this->doc(fields: ['expiry' => $this->field('2027-01-01')])]);
        $p = $r->proposals()->first();
        $v = $this->service->review($p, $this->reviewData($p, ['expiry_precision' => 'day', 'expiry_value' => '2027-01-01', 'number' => 'USER-EDITED']), $this->user->id);
        $this->assertTrue($v->confirmed);
        $this->assertSame('USER-EDITED', $v->number);
        $this->assertSame('document_reviewed', $v->provenance['expiry_origin']);
        $this->assertSame('Mock scope', $v->provenance['scope_text']);
        $this->assertSame($v->id, $this->service->review($p, [], $this->user->id)->id);
        $this->assertDatabaseCount('dossier_versions', 1);
        $e = app(DossierExportService::class)->create($this->machine, [], [$v->id], (string) Str::uuid(), true, $this->user->id);
        \Tests\Support\DossierTestWorker::run($e->id, 'export');
        $this->assertSame('ready', $e->fresh()->status);
        $pdf = new \setasign\Fpdi\Fpdi;
        $this->assertSame(3, $pdf->setSourceFile(Storage::disk('dossiers')->path($e->fresh()->path)));
        $sheet = (new DossierWorkbook)->sheets()[1];
        $this->assertSame('USER-EDITED', $sheet->array()[0][6]);
        $this->assertSame('2027-01-01', $sheet->array()[0][12]);
    }

    public function test_stale_ai_never_overwrites_manual_edits_and_review_requires_live_revision(): void
    {
        $r = $this->completed([$this->doc()]);
        $p = $r->proposals()->first();
        $this->source->increment('revision');
        try {
            $this->service->review($p, $this->reviewData($p), $this->user->id);
            $this->fail('stale accepted');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('cũ hơn', $e->getMessage());
        }
        $v = $this->service->review($p, $this->reviewData($p, ['acknowledge_stale' => true, 'confirmed' => false]), $this->user->id);
        $v->update(['number' => 'manual edit']);
        $this->service->review($p, ['number' => 'late ai'], $this->user->id);
        $this->assertSame('manual edit', $v->fresh()->number);
        $this->assertFalse($v->fresh()->confirmed);
    }

    public function test_rules_health_anchor_leap_month_and_direct_expiry_always_wins(): void
    {
        $type = DossierType::where('ai_key', 'health')->first();
        DB::table('dossier_ai_rules')->where('type_id', $type->id)->update(['enabled' => true]);
        $d = $this->doc('health', [1], ['health_signed_on' => $this->field('2024-02-29'), 'certified_on' => $this->field('2026-01-01')]);
        $d['subject'] = 'driver';
        $d['expiry_absent'] = true;
        $s = app(DossierAiSchema::class);
        $result = $s->documents($this->group([$d]), 3, ['group' => 'machine'])[0];
        $this->assertSame('2025-02-28', $result['data']['expiry_suggestion']['value']);
        $this->assertSame('health_signed_on', $result['data']['expiry_suggestion']['anchor']);
        $d['fields']['expiry'] = $this->field('2024-12-31');
        $this->assertNull($s->documents($this->group([$d]), 3, [])[0]['data']['expiry_suggestion']);
        $this->assertFalse($s->validDate('2025-02-29', 'Y-m-d'));
        $this->assertTrue($s->validDate('2024-02-29', 'Y-m-d'));
        $d['fields']['expiry'] = $this->field('2027-02', 'month');
        $this->assertSame('month', $s->documents($this->group([$d]), 3, [])[0]['data']['fields']['expiry']['precision']);
    }

    public function test_only_two_asset_groups_distinct_registration_and_inspections_no_year_required(): void
    {
        $this->assertNotSame(DossierType::where('ai_key', 'registration')->value('id'), DossierType::where('ai_key', 'vehicle_inspection')->value('id'));
        $s = app(DossierAiSchema::class);
        $r = $s->documents($this->group([$this->doc('vehicle_inspection', fields: ['expiry' => $this->field('2026-11-01')])]), 3, ['group' => 'machine']);
        $this->assertStringContainsString('không phù hợp', implode(' ', $r[0]['issues']));
        $this->assertNull($r[0]['data']['expiry_suggestion']);
        $this->assertSame('2026-11-01', $r[0]['data']['fields']['expiry']['value']);
        $this->post(route('dossiers.group', $this->machine), ['dossier_group' => 'excavator'])->assertSessionHasErrors();
        $this->assertSame('machine', $this->machine->fresh()->dossier_group);
    }

    public function test_invalid_date_missing_evidence_and_multidocument_require_manual_processing(): void
    {
        $s = app(DossierAiSchema::class);
        $d = $this->doc(fields: ['issued_on' => $this->field('2026-02-30')]);
        $d['multiple_documents'] = true;
        $r = $this->completed([$d]);
        $p = $r->proposals()->first();
        $this->assertArrayNotHasKey('issued_on', $p->data['fields']);
        try {
            $this->service->review($p, $this->reviewData($p), $this->user->id);
            $this->fail('multi-document accepted');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('dossier_versions', 0);
        }
        $d = $this->doc(fields: ['expiry' => ['value' => '2027-01-01', 'precision' => 'day', 'page' => 1]]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $s->documents($this->group([$d]), 3, []);
    }

    public function test_pause_retry_bounded_failures_and_late_lease_is_fenced(): void
    {
        $this->mockVision([new \RuntimeException('PRIVATE SHOULD NOT LEAK'), new \RuntimeException('PRIVATE'), new \RuntimeException('PRIVATE')]);
        $r = $this->service->request($this->source, $this->user->id);
        $this->service->control($r, 'pause', null, $this->user->id);
        $this->assertSame(0, $this->service->step($r->id));
        $this->assertSame(0, $r->pages()->first()->attempts);
        $this->service->control($r, 'resume', null, $this->user->id);
        $this->drain($r, 3);
        $p = $r->pages()->where('page', 1)->first();
        $this->assertSame('failed', $p->status);
        $this->assertSame(3, $p->attempts);
        $this->assertStringNotContainsString('PRIVATE', $p->error);
        $this->service->control($r, 'retry', 1, $this->user->id);
        $this->assertSame(300, $p->fresh()->dpi);
        $this->assertSame(0, $p->fresh()->attempts);
        $this->service->step($r->id); // Scheduling alone never consumes a page.
        $this->assertSame(0, $p->fresh()->attempts);
    }

    public function test_bic_two_people_no_automatic_identity_assignment_or_zalo_mutation(): void
    {
        $a = Driver::create(['name' => 'Mock A']);
        $b = Driver::create(['name' => 'Mock B']);
        $d = $this->doc('bic_personal', [1, 3]);
        $d['subject'] = 'driver';
        $d['insured'] = [['name' => 'Mock A', 'page' => 3, 'quote' => 'Mock A included']];
        $r = $this->completed([$d]);
        $this->assertSame([1, 3], $r->proposals()->first()->data['pages']);
        $this->assertCount(1, $r->proposals()->first()->data['insured']);
        $this->assertStringContainsString('chưa có định danh', implode(' ', $r->proposals()->first()->issues));
        $this->assertDatabaseCount('dossier_sender_profiles', 0);
        $this->assertDatabaseCount('machine_driver_histories', 0);
        $this->assertDatabaseCount('ocr_jobs', 0);
        $this->assertDatabaseCount('dossier_versions', 0);
    }

    public function test_backend_gate_protects_ai_request_control_review_images_rules(): void
    {
        $r = $this->completed([$this->doc()]);
        $p = $r->proposals()->first();
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->post(route('dossiers.ai.request', $this->source))->assertForbidden();
        $this->post(route('dossiers.ai.control', $r), ['action' => 'pause'])->assertForbidden();
        $this->post(route('dossiers.ai.review', $p))->assertForbidden();
        $this->get(route('dossiers.ai.image', [$r, 1]))->assertForbidden();
        $this->get(route('dossiers.ai.rules'))->assertForbidden();
        $this->post(route('dossiers.ai.bulk', $r))->assertForbidden();
    }

    public function test_bulk_review_selected_forms_is_atomic_and_does_not_select_other_proposals(): void
    {
        $r = $this->completed([$this->doc(pages: [1]), $this->doc('asset_insurance', [2]), $this->doc('machine_inspection', [3])]);
        $ps = $r->proposals()->orderBy('position')->get();
        $items = $ps->take(2)->map(function ($p) {
            $d = $this->reviewData($p);
            unset($d['pages']);
            $d['page_numbers'] = implode(',', $p->data['pages']);

            return ['id' => $p->id, 'data' => $d];
        })->all();
        $this->postJson(route('dossiers.ai.bulk', $r), ['items' => $items])->assertOk();
        $this->assertDatabaseCount('dossier_versions', 2);
        $this->assertNull($ps[2]->fresh()->version_id);
        $this->postJson(route('dossiers.ai.bulk', $r), ['items' => $items])->assertStatus(422);
        $this->assertDatabaseCount('dossier_versions', 2);
    }

    public function test_hour_precision_unknown_and_permanent_do_not_mix_with_renewal_date(): void
    {
        $d = $this->doc('asset_insurance', fields: ['effective_at' => $this->field('2026-10-30 10:00:00', 'minute'), 'expiry' => $this->field('2026-10-30 09:59:00', 'minute')]);
        $n = app(DossierAiSchema::class)->documents($this->group([$d]), 3, ['group' => 'machine'])[0];
        $this->assertStringContainsString('giờ trước', implode(' ', $n['issues']));
        $r = $this->completed([$this->doc('asset_insurance')]);
        $p = $r->proposals()->first();
        $v = $this->service->review($p, $this->reviewData($p, ['expiry_precision' => 'permanent', 'renewal_on' => '2027-01-01']), $this->user->id);
        $this->assertSame('permanent', $v->expiry_precision);
        $this->assertNull($v->expiry_value);
        $this->assertSame('2027-01-01', $v->renewal_on);
        $this->assertSame('user_reviewed', $v->provenance['expiry_origin']);
    }

    public function test_expired_ai_claim_cannot_publish_after_remote_reclaim(): void
    {
        $this->mockVision([$this->page()]);
        $r = $this->service->request($this->source, $this->user->id);
        $jobs = app(\App\Services\Dossiers\DossierRemoteJobs::class);
        $w = \Tests\Support\DossierTestWorker::worker();
        $j = $jobs->claim($w);
        $claim = [$w->id, $j->claim_token, $j->attempts];
        $j->update(['lease_until' => now()->subSecond()]);
        $new = $jobs->claim($w);
        $this->assertSame(2, $new->attempts);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $jobs->complete($j, $claim, []);
    }

    public function test_future_replacement_preserves_current_and_manual_provenance_edits(): void
    {
        $r = $this->completed([$this->doc()]);
        $p = $r->proposals()->first();
        $old = $this->service->review($p, $this->reviewData($p), $this->user->id);
        $new = $this->source->replicate();
        $new->path = 'sources/new.pdf';
        $bytes = Storage::disk('dossiers')->get($this->source->path)."\n%replacement fixture\n";
        Storage::disk('dossiers')->put($new->path, $bytes);
        $new->sha256 = hash('sha256', $bytes);
        $new->revision = 0;
        $new->save();
        $this->source = $new;
        $r2 = $this->completed([$this->doc()]);
        $p2 = $r2->proposals()->first();
        $future = $this->service->review($p2, $this->reviewData($p2, ['effective_at' => '2027-01-01 00:00:00']), $this->user->id);
        $this->assertSame($old->id, $future->replaces_id);
        $this->assertSame($old->id, app(DossierStatus::class)->current($old->record->fresh())?->id);
        $this->assertDatabaseCount('dossier_versions', 2);
        $this->assertSame('user_reviewed', $future->provenance['expiry_origin']);
    }

    public function test_backend_requires_evidence_and_cannot_enable_legal_vehicle_cycle(): void
    {
        $r = $this->completed([$this->doc()]);
        $p = $r->proposals()->first();
        $data = $this->reviewData($p, ['reviewed_evidence' => false]);
        $this->postJson(route('dossiers.ai.review', $p), [...$data, 'page_numbers' => '1,2,3'])->assertStatus(422);
        $this->assertDatabaseCount('dossier_versions', 0);
        $this->postJson(route('dossiers.ai.rules.save'), ['type_id' => DossierType::where('ai_key', 'vehicle_inspection')->value('id'), 'months' => 12, 'anchor' => 'issued_on', 'scope' => 'unverified', 'basis' => 'unverified', 'effective_from' => '2026-10-30', 'enabled' => true])->assertStatus(422);
        $this->get(route('dossiers.ai.rules'))->assertOk()->assertSee('chưa xác minh pháp lý');
    }

    public function test_rule_changes_reuse_page_cache_and_only_group_again(): void
    {
        $doc = $this->doc();
        $this->mockVision([$this->page(), $this->page(), $this->page(), $this->group([$doc]), $this->group([$doc])]);
        $r = $this->service->request($this->source, $this->user->id);
        $this->drain($r);
        DB::table('dossier_ai_rules')->where('type_id', DossierType::where('ai_key', 'machine_inspection')->value('id'))->update(['enabled' => true]);
        $next = $this->service->request($this->source, $this->user->id);
        $this->assertNotSame($r->id, $next->id);
        $this->assertSame(3, $next->pages()->where('status', 'ready')->count());
        $this->assertNotNull($next->pages()->first()->raw['cached_from_page_id']);
        $this->drain($next, 1);
        $this->assertSame('ready', $next->fresh()->status);
        $this->assertSame(0, $next->pages()->sum('attempts'));
        $this->assertSame(0, $next->pages()->sum('elapsed_ms'));
    }

    public function test_auto_queue_when_thumbnails_finish_does_not_call_api(): void
    {
        config(['dossiers.ai.auto_analyze' => true]);
        $this->source->update(['status' => 'queued', 'progress' => 0]);
        \Tests\Support\DossierTestWorker::run($this->source->id, 'prepare');
        $this->assertSame('ready', $this->source->fresh()->status);
        $this->assertDatabaseCount('dossier_ai_runs', 1);
        Queue::assertNotPushed(AnalyzeDossier::class);
    }

    public function test_rule_application_requires_scope_and_unchanged_anchor_and_records_version(): void
    {
        $type = DossierType::where('ai_key', 'machine_inspection')->first();
        DB::table('dossier_ai_rules')->where('type_id', $type->id)->update(['enabled' => true]);
        $d = $this->doc(fields: ['issued_on' => $this->field('2024-01-31')]);
        $d['expiry_absent'] = true;
        $r = $this->completed([$d]);
        $p = $r->proposals()->first();
        $this->assertSame('2025-01-31', $p->data['expiry_suggestion']['value']);
        try {
            $this->service->review($p, $this->reviewData($p, ['use_rule' => true, 'issued_on' => '2024-01-31']), $this->user->id);
            $this->fail('scope missing accepted');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('dossier_versions', 0);
        }
        try {
            $this->service->review($p, $this->reviewData($p, ['use_rule' => true, 'rule_scope_confirmed' => true, 'issued_on' => '2024-02-01']), $this->user->id);
            $this->fail('changed anchor accepted');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseCount('dossier_versions', 0);
        }
        $v = $this->service->review($p, $this->reviewData($p, ['use_rule' => true, 'rule_scope_confirmed' => true, 'issued_on' => '2024-01-31']), $this->user->id);
        $this->assertSame('rule_reviewed', $v->provenance['expiry_origin']);
        $this->assertSame('2025-01-31', $v->expiry_value);
        $this->assertSame('business-2026-10-30-v1', $v->provenance['rule']['version']);
    }

    public function test_failed_schema_does_not_publish_and_retry_uses_same_page(): void
    {
        $this->mockVision([['invalid' => 'private'], $this->page(), $this->page(), $this->page(), $this->group([$this->doc()])]);
        $r = $this->service->request($this->source, $this->user->id);
        $this->drain($r, 1);
        $this->assertSame(0, $r->pages()->where('status', 'ready')->count());
        $this->assertDatabaseCount('dossier_ai_proposals', 0);
        $this->drain($r, 4);
        $this->assertSame('ready', $r->fresh()->status);
        $this->assertSame(2, $r->pages()->orderBy('page')->first()->attempts);
        $page = $r->pages()->orderBy('page')->first();
        $this->assertCount(2, $page->raw['attempts']);
        $this->assertSame(20, $page->usage['prompt_tokens']);
    }

    public function test_remote_completion_enforces_spacing_and_auto_pilot_cap(): void
    {
        $this->mockVision([$this->page()]);
        $r = $this->service->request($this->source, $this->user->id);
        $this->remoteStep($r);
        $this->assertNull(app(\App\Services\Dossiers\DossierRemoteJobs::class)->claim(\Tests\Support\DossierTestWorker::worker()));
        $this->assertSame(0, $r->pages()->where('page', 2)->first()->attempts);
        $r->update(['cache_key' => hash('sha256', 'old')]);
        foreach (range(1, 9) as $n) {
            $copy = $r->replicate();
            $copy->cache_key = hash('sha256', 'old-'.$n);
            $copy->save();
        }
        $next = $this->service->request($this->source, $this->user->id, true);
        $this->assertTrue($next->paused);
    }

    public function test_disabled_ai_worker_cannot_dispatch_or_consume_pending_work(): void
    {
        config(['dossiers.ai.enabled' => false, 'dossiers.ai.endpoint' => 'https://mock.invalid/v1', 'dossiers.ai.key' => 'not-real', 'dossiers.ai.model' => 'mock']);
        $this->assertFalse(app(DossierVision::class)->probe()['configured']);
        $run = $this->service->request($this->source, $this->user->id);
        $run->update(['status' => 'queued']);
        $this->mock(DossierVision::class)->shouldNotReceive('read');
        $this->artisan('dossiers:ai-work')->expectsOutput('AI hồ sơ đang tắt; không xếp hoặc gọi API.')->assertExitCode(0);
        Queue::assertNotPushed(AnalyzeDossier::class);
        $this->assertSame(0, $run->pages()->sum('attempts'));
    }

    public function test_upload_stays_manual_until_auto_analysis_is_explicitly_enabled(): void
    {
        config(['dossiers.ai.auto_analyze' => false]);
        $this->source->update(['status' => 'queued', 'progress' => 0]);
        \Tests\Support\DossierTestWorker::run($this->source->id, 'prepare');
        $this->assertSame('ready', $this->source->fresh()->status);
        $this->assertDatabaseCount('dossier_ai_runs', 0);
        Queue::assertNotPushed(AnalyzeDossier::class);
    }
}
