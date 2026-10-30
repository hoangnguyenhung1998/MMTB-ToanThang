<?php

namespace Tests\Feature;

use App\Models\DossierBatch;
use App\Models\DossierObject;
use App\Models\DossierSource;
use App\Models\DossierWorker;
use App\Models\DossierWorkerJob;
use App\Models\Machine;
use App\Models\User;
use App\Services\Dossiers\DossierObjects;
use App\Services\Dossiers\DossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DossierRemoteTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private DossierSource $source;

    private array $heartbeat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->token = str_repeat('test-only-', 5);
        $disk = Storage::fake('remote-test', ['throw' => true]);
        Storage::set('remote-test', new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends \Illuminate\Filesystem\FilesystemAdapter
        {
            public function path($path)
            {
                throw new \RuntimeException('Remote adapter has no local path');
            }
        });
        config(['dossiers.storage_disk' => 'remote-test', 'dossiers.worker.token' => $this->token, 'dossiers.ai.enabled' => false]);
        $user = User::factory()->create();
        config(['dossiers.allowed_user_ids' => [$user->id]]);
        $this->actingAs($user);
        Machine::create(['asset_code' => 'T-R200', 'company' => 'VINCONS', 'chassis_no' => 'R200', 'status' => 'ACTIVE']);
        $batch = DossierBatch::create(['id' => (string) Str::uuid(), 'user_id' => $user->id]);
        $pdf = new \FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Text(10, 20, 'Protocol fixture only');
        $this->source = app(DossierService::class)->upload($batch, UploadedFile::fake()->createWithContent('T-R200.pdf', $pdf->Output('S')), $user->id)['source'];
        $this->heartbeat = ['worker_id' => 'test-one', 'capabilities' => ['prepare', 'export'], 'versions' => ['engine' => 'test-only'], 'ai_ready' => false];
    }

    private function auth(array $extra = []): array
    {
        return ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token, ...$extra];
    }

    private function claim(): array
    {
        $this->postJson('/api/dossiers/v1/heartbeat', $this->heartbeat, $this->auth())->assertOk();

        return $this->postJson('/api/dossiers/v1/jobs/claim', ['worker_id' => 'test-one'], $this->auth())->assertOk()->json('job');
    }

    private function headers(array $j): array
    {
        return $this->auth(['X-Dossier-Worker' => 'test-one', 'X-Dossier-Claim' => $j['claim_token'], 'X-Dossier-Attempt' => (string) $j['attempt']]);
    }

    private function payload(array $j): array
    {
        return ['input_hash' => $j['input_hash'], 'pipeline' => $j['pipeline'], 'result' => ['page_count' => 1, 'geometry_before' => [['size:1', 'rot:0', 'MediaBox:1', 'CropBox:1', 'BleedBox:1', 'TrimBox:1', 'ArtBox:1']], 'geometry_after' => [['size:1', 'rot:0', 'MediaBox:1', 'CropBox:1', 'BleedBox:1', 'TrimBox:1', 'ArtBox:1']], 'normalize_exit' => 0, 'warnings' => [], 'qpdf_version' => 'test-12.3.2', 'validation' => ['qpdf_check_exit' => 0, 'reopened_pages' => 1, 'trial_export_pages' => 1, 'rasterized' => false]]];
    }

    private function upload(array $j, string $slot, UploadedFile $file): void
    {
        $this->post('/api/dossiers/v1/jobs/'.$j['id'].'/outputs/'.$slot, ['file' => $file, 'sha256' => hash_file('sha256', $file->getRealPath())], $this->headers($j))->assertOk();
    }

    public function test_credentials_scoped_inputs_and_offline_queue_never_require_server_binaries_or_path(): void
    {
        $this->postJson('/api/dossiers/v1/jobs/claim', ['worker_id' => 'test-one'])->assertUnauthorized();
        $this->postJson('/api/dossiers/v1/heartbeat', $this->heartbeat, ['Authorization' => 'Bearer unrelated-ocr-token'])->assertUnauthorized();
        $this->assertSame('queued', $this->source->status);
        $this->assertDatabaseCount('dossier_worker_jobs', 1);
        $j = $this->claim();
        $this->assertSame('prepare', $j['kind']);
        $id = $j['input']['objects']['source']['id'];
        $this->get('/api/dossiers/v1/jobs/'.$j['id'].'/inputs/'.$id, $this->headers($j))->assertOk();
        $this->get('/api/dossiers/v1/jobs/'.$j['id'].'/inputs/'.Str::uuid(), $this->headers($j))->assertNotFound();
        $this->postJson('/api/dossiers/v1/jobs/claim', ['worker_id' => 'test-one'], $this->auth())->assertJsonPath('job', null);
        $this->postJson('/api/dossiers/v1/jobs/'.$j['id'].'/renew', ['stage' => 'thumbnail', 'progress' => 1], $this->headers($j))->assertOk();
        $this->get(route('dossiers.file', [$this->source, 'source']))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('dossiers.file', [$this->source, 'source']))->assertForbidden();
    }

    public function test_expired_reclaimed_owner_fenced_and_missing_hash_or_output_never_published(): void
    {
        $old = $this->claim();
        DossierWorkerJob::whereKey($old['id'])->update(['lease_until' => now()->subSecond()]);
        $j = $this->claim();
        $this->assertSame(2, $j['attempt']);
        $this->assertNotSame($old['claim_token'], $j['claim_token']);
        $this->postJson('/api/dossiers/v1/jobs/'.$j['id'].'/renew', [], $this->headers($old))->assertConflict();
        $this->postJson('/api/dossiers/v1/jobs/'.$j['id'].'/complete', $this->payload($j), $this->headers($old))->assertConflict();
        $this->postJson('/api/dossiers/v1/jobs/'.$j['id'].'/complete', $this->payload($j), $this->headers($j))->assertUnprocessable();
        $file = UploadedFile::fake()->createWithContent('output.pdf', '%PDF-1.4 test only');
        $this->post('/api/dossiers/v1/jobs/'.$j['id'].'/outputs/processed', ['file' => $file, 'sha256' => str_repeat('0', 64)], $this->headers($j))->assertUnprocessable();
        $this->assertNull($this->source->fresh()->processed_object_id);
        $this->assertDatabaseCount('dossier_versions', 0);
    }

    public function test_upload_complete_replay_is_idempotent_and_no_staged_file_is_public(): void
    {
        $j = $this->claim();
        $source = app(DossierObjects::class)->source($this->source);
        $bytes = Storage::disk($source->disk)->get($source->key);
        $pdf = UploadedFile::fake()->createWithContent('output.pdf', $bytes);
        $image = UploadedFile::fake()->image('preview.png', 20, 20);
        $this->upload($j, 'processed', $pdf);
        $this->upload($j, 'processed', $pdf);
        $this->upload($j, 'thumbnail-1', $image);
        $staged = DossierObject::where('slot', 'processed')->firstOrFail();
        $this->assertSame('staged', $staged->status);
        $this->get(route('dossiers.file', [$this->source, 'processed']))->assertNotFound();
        $payload = $this->payload($j);
        $endpoint = '/api/dossiers/v1/jobs/'.$j['id'].'/complete';
        $this->postJson($endpoint, $payload, $this->headers($j))->assertOk()->assertJsonPath('status', 'succeeded');
        $this->postJson($endpoint, $payload, $this->headers($j))->assertOk();
        $this->upload($j, 'processed', $pdf);
        $this->assertDatabaseCount('dossier_objects', 3);
        $this->assertSame('ready', $this->source->fresh()->status);
        $this->assertSame($source->sha256, hash('sha256', Storage::disk($source->disk)->get($source->key)));
        $payload['result']['page_count'] = 2;
        $this->postJson($endpoint, $payload, $this->headers($j))->assertConflict();
        $this->get(route('dossiers.file', [$this->source, 'processed']))->assertOk();
    }

    public function test_failure_backoff_limit_and_worker_offline_does_not_invent_success(): void
    {
        $j = $this->claim();
        $this->postJson('/api/dossiers/v1/jobs/'.$j['id'].'/fail', ['code' => 'network_interrupted', 'retryable' => true], $this->headers($j))->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson('/api/dossiers/v1/jobs/claim', ['worker_id' => 'test-one'], $this->auth())->assertJsonPath('job', null);
        DossierWorkerJob::whereKey($j['id'])->update(['available_at' => now()->subSecond(), 'attempts' => 3]);
        $this->postJson('/api/dossiers/v1/jobs/claim', ['worker_id' => 'test-one'], $this->auth())->assertJsonPath('job', null);
        $this->assertSame('failed', $this->source->fresh()->status);
        $this->post(route('dossiers.source.retry', $this->source))->assertRedirect();
        $this->assertDatabaseCount('dossier_worker_jobs', 1);
        DossierWorker::whereKey('test-one')->update(['last_seen_at' => now()->subMinutes(5)]);
        $this->get(route('dossiers.source', $this->source))->assertOk()->assertSee('Đang chờ worker / offline');
        $this->assertSame('prepare', $this->claim()['kind']);
    }

    public function test_legacy_copy_is_dry_run_by_default_idempotent_and_retains_source_and_manifest(): void
    {
        $legacy = Storage::fake('dossiers');
        $bytes = '%PDF-1.4 legacy bytes';
        $legacy->put('sources/old.pdf', $bytes);
        $s = $this->source;
        $old = $s->object_id;
        $s->update(['object_id' => null, 'path' => 'sources/old.pdf', 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)]);
        $this->artisan('dossiers:migrate-objects', ['--disk' => 'remote-test'])->assertSuccessful();
        $this->assertNull($s->fresh()->object_id);
        $this->artisan('dossiers:migrate-objects', ['--disk' => 'remote-test', '--execute' => true, '--backup-verified' => true])->assertSuccessful();
        $bound = $s->fresh()->object_id;
        $this->assertNotNull($bound);
        $count = DossierObject::count();
        $this->artisan('dossiers:migrate-objects', ['--disk' => 'remote-test', '--execute' => true, '--backup-verified' => true])->assertSuccessful();
        $this->assertSame($bound, $s->fresh()->object_id);
        $this->assertSame($count, DossierObject::count());
        $legacy->assertExists('sources/old.pdf');
    }

    public function test_partial_upload_wrong_input_and_unknown_warning_remain_private(): void
    {
        $j = $this->claim();
        $id = $j['id'];
        $headers = $this->headers($j);
        $file = UploadedFile::fake()->createWithContent('partial.pdf', '%PDF-1.4 partial');
        $broken = new UploadedFile($file->getRealPath(), 'partial.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL, true);
        $this->post('/api/dossiers/v1/jobs/'.$id.'/outputs/processed', ['file' => $broken, 'sha256' => hash_file('sha256', $file->getRealPath())], $headers)->assertUnprocessable();
        $this->assertDatabaseCount('dossier_objects', 1);
        $payload = $this->payload($j);
        $payload['input_hash'] = str_repeat('0', 64);
        $this->postJson('/api/dossiers/v1/jobs/'.$id.'/complete', $payload, $headers)->assertConflict();
        $payload = $this->payload($j);
        $payload['result']['normalize_exit'] = 3;
        $payload['result']['warnings'] = ['unknown_damage'];
        $this->postJson('/api/dossiers/v1/jobs/'.$id.'/complete', $payload, $headers)->assertUnprocessable();
        $this->assertNull($this->source->fresh()->processed_object_id);
        $this->assertSame('queued', $this->source->fresh()->status);
    }
}
