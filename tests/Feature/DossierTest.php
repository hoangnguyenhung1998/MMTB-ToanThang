<?php

namespace Tests\Feature;

use App\Exports\DossierWorkbook;
use App\Jobs\BuildDossierExport;
use App\Jobs\ProcessDossierSource;
use App\Models\DossierBatch;
use App\Models\DossierExport;
use App\Models\DossierSource;
use App\Models\DossierType;
use App\Models\DossierVersion;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineDriverHistory;
use App\Models\User;
use App\Models\ZaloMessage;
use App\Services\Dossiers\DossierExportService;
use App\Services\Dossiers\DossierPdf;
use App\Services\Dossiers\DossierReminderService;
use App\Services\Dossiers\DossierReport;
use App\Services\Dossiers\DossierService;
use App\Services\Dossiers\DossierStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class DossierTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Machine $machine;

    private DossierService $service;

    private string $poppler;

    private function missingXrefSelfEntry(): UploadedFile
    {
        $content = 'BT /F1 14 Tf 20 100 Td (XREF SELF ENTRY REGRESSION) Tj ET';
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length '.strlen($content).' >>'."\nstream\n".$content."\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pdf = "%PDF-1.6\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n." 0 obj\n".$body."\nendobj\n";
        }
        $start = strlen($pdf);
        $xref = chr(0).pack('Nn', 0, 65535);
        foreach ($offsets as $offset) {
            $xref .= chr(1).pack('Nn', $offset, 0);
        }
        // Size includes object6, Index intentionally omits the xref stream itself.
        $pdf .= "6 0 obj\n<< /Type /XRef /Root 1 0 R /Size 7 /W [1 4 2] /Index [0 6] /Length ".strlen($xref)." >>\nstream\n".$xref."\nendstream\nendobj\nstartxref\n".$start."\n%%EOF\n";

        return UploadedFile::fake()->createWithContent('T-XX0769.pdf', $pdf);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['dossiers.ai.enabled' => false]);
        Storage::set('dossiers', Storage::fake('dossiers-'.getmypid(), ['throw' => true]));
        Queue::fake();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        config(['dossiers.allowed_user_ids' => [$this->user->id]]);
        $this->actingAs($this->user);
        $this->machine = Machine::create(['asset_code' => 'T-XX0769', 'company' => 'VINCONS', 'chassis_no' => 'F-769', 'status' => 'ACTIVE']);
        $this->service = app(DossierService::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00:00', 'Asia/Ho_Chi_Minh'));
        $this->poppler = getenv('DOSSIERS_PDFTOPPM') ?: 'C:/Users/Administrator/.cache/codex-runtimes/codex-primary-runtime/dependencies/native/poppler/Library/bin/pdftoppm.exe';
        if (is_file($this->poppler)) {
            config(['dossiers.pdftoppm' => $this->poppler]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function batch(): DossierBatch
    {
        return DossierBatch::create(['id' => Str::uuid(), 'user_id' => $this->user->id]);
    }

    private function pdf(string $name = 'T-XX0769.pdf', string $marker = 'fixture'): UploadedFile
    {
        $pdf = new \FPDF;
        $pdf->SetCompression(false);
        $pdf->SetFont('Helvetica', '', 12);
        foreach ([['P', 0], ['L', 90], ['P', 0], ['P', 0]] as $i => [$orientation, $rotation]) {
            $pdf->AddPage($orientation, 'A4', $rotation);
            if ($i !== 2) { // Deliberately blank third page; fourth has two sides represented on one page.
                $pdf->Text(20, 20, $marker.' PAGE '.($i + 1));
                if ($i === 3) {
                    $pdf->Text(20, 140, 'SECOND SIDE ON SAME PAGE');
                }
            }
        }

        return UploadedFile::fake()->createWithContent($name, $pdf->Output('S'));
    }

    private function source(string $marker = 'fixture', bool $process = false): DossierSource
    {
        $source = $this->service->upload($this->batch(), $this->pdf(marker: $marker), $this->user->id)['source'];
        if ($process) {
            $job = new ProcessDossierSource($source->id);
            for ($i = 0; $i < 5; $i++) {
                $job->handle(app(DossierPdf::class));
            }
        } else {
            $source->update(['status' => 'ready', 'page_count' => 4, 'progress' => 4]);
        }
        $source->refresh();
        $this->service->saveSource($source, ['machine_id' => $this->machine->id, 'revision' => $source->revision, 'excluded_pages' => [], 'confirm' => false], $this->user->id);

        return $source->fresh();
    }

    private function data(DossierSource $source, array $changes = []): array
    {
        $type = DossierType::whereJsonContains('groups', 'machine')->firstOrFail();

        return array_replace(['request_id' => (string) Str::uuid(), 'source_id' => $source->id, 'type_id' => $type->id,
            'subject' => 'machine', 'subject_id' => $this->machine->id, 'instance' => 'main',
            'pages' => [['page' => 1, 'rotation' => 0], ['page' => 4, 'rotation' => 90]], 'shared_pages' => false,
            'number' => 'NO-1', 'issuer' => 'Fixture', 'issued_on' => '2026-01-01', 'certified_on' => '2026-02-01',
            'effective_at' => '2026-01-01 00:00:00', 'expiry_precision' => 'day', 'expiry_value' => '2026-11-10',
            'month_rule' => null, 'note' => null, 'reason' => 'Nhập ban đầu', 'confirmed' => true], $changes);
    }

    public function test_batch_partial_success_checksum_duplicate_unknown_and_ambiguous_asset(): void
    {
        $batch = $this->batch();
        $original = $this->pdf();
        $one = $this->postJson(route('dossiers.upload', $batch), ['file' => $original])->assertOk()->assertJsonPath('duplicate', false);
        $this->postJson(route('dossiers.upload', $batch), ['file' => UploadedFile::fake()->createWithContent('bad.pdf', 'not a pdf')])->assertUnprocessable();
        $this->postJson(route('dossiers.upload', $batch), ['file' => $original])->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $one->json('id'));
        $unknown = $this->service->upload($batch, $this->pdf('NO-MACHINE.pdf', 'unknown'), $this->user->id)['source'];
        $this->assertNull($unknown->machine_id);
        $this->assertSame('NOT_FOUND', $unknown->resolution);
        Machine::create(['asset_code' => 'TXX0769', 'company' => 'VINCONS', 'chassis_no' => 'F-other', 'status' => 'ACTIVE']);
        $this->app->forgetInstance(\App\Services\AssetCodeResolver::class);
        $ambiguous = $this->service->upload($batch, $this->pdf(marker: 'ambiguous'), $this->user->id)['source'];
        $this->assertNull($ambiguous->machine_id);
        $this->assertSame('AMBIGUOUS', $ambiguous->resolution);
        $this->assertSame(2, Machine::count());
        $this->assertSame(3, DossierSource::count());
        Queue::assertPushed(ProcessDossierSource::class, 3);
    }

    public function test_real_pdf_thumbnails_multipage_rotation_blank_source_bytes_and_retry(): void
    {
        if (! is_file($this->poppler) && ! getenv('DOSSIERS_PDFTOPPM')) {
            $this->markTestSkipped('Poppler unavailable: actual thumbnails not verified.');
        }
        $source = $this->source(process: true);
        $this->assertSame('ready', $source->status);
        $this->assertSame(4, $source->page_count);
        $hash = hash_file('sha256', Storage::disk('dossiers')->path($source->path));
        $this->assertSame($source->sha256, $hash);
        Storage::disk('dossiers')->assertExists('thumbnails/'.$source->id.'/3.png');
        $job = new ProcessDossierSource($source->id);
        $job->handle(app(DossierPdf::class));
        $this->assertSame(4, $source->fresh()->progress);
        $this->assertSame($hash, hash_file('sha256', Storage::disk('dossiers')->path($source->path)));
        $v = $this->service->saveVersion($this->data($source), $this->user->id);
        $this->assertSame([1, 4], array_column($v->pages, 'page'));
        $this->get(route('dossiers.source', $source))->assertOk()->assertSee('Chưa phân loại');
        $this->get(route('dossiers.file', [$source, 'thumbnail', 3]))->assertOk();
        $this->get(route('dossiers.file', [$source, 'thumbnail', 5]))->assertNotFound();
    }

    public function test_invalid_pdf_does_not_destroy_source_and_failed_job_can_resume(): void
    {
        $source = $this->source();
        Storage::disk('dossiers')->put('corrupt.pdf', '%PDF-1.4 broken');
        try {
            app(DossierPdf::class)->inspect(Storage::disk('dossiers')->path('corrupt.pdf'));
            $this->fail('Invalid PDF accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PDF hỏng', $e->getMessage());
        }
        $source->update(['status' => 'queued', 'page_count' => null, 'progress' => 0]);
        config(['dossiers.pdftoppm' => 'does-not-exist-dossier-pdftoppm']);
        $job = new ProcessDossierSource($source->id);
        try {
            $job->handle(app(DossierPdf::class));
            $this->fail('Missing Poppler accepted');
        } catch (\Throwable $e) {
            $job->failed($e);
        }
        $this->assertSame('failed', $source->fresh()->status);
        $this->assertSame(0, $source->fresh()->progress);
        $this->post(route('dossiers.source.retry', $source))->assertRedirect();
        Queue::assertPushed(ProcessDossierSource::class);
        Storage::disk('dossiers')->assertExists($source->path);
    }

    public function test_persisted_draft_overlap_exclusions_and_per_source_confirmation(): void
    {
        $source = $this->source();
        $data = $this->data($source, ['confirmed' => false]);
        $v = $this->service->saveVersion($data, $this->user->id);
        $again = $this->service->saveVersion($data, $this->user->id);
        $this->assertSame($v->id, $again->id);
        $this->get(route('dossiers.source', $source))->assertOk()->assertSee('NO-1');
        try {
            $this->service->saveVersion($this->data($source, ['instance' => 'different']), $this->user->id);
            $this->fail('Duplicate pages accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Trang dùng trùng', $e->getMessage());
        }
        $data['confirmed'] = true;
        $this->service->saveVersion($data, $this->user->id, $v);
        $source->refresh();
        $this->service->saveSource($source, ['machine_id' => $this->machine->id, 'revision' => $source->revision, 'excluded_pages' => [2, 3], 'confirm' => true], $this->user->id);
        $this->assertTrue($source->fresh()->confirmed);
        $this->assertDatabaseHas('activity_logs', ['event' => 'dossier.version_confirmed']);
        $this->assertSame(1, DossierVersion::count());
    }

    public function test_intentional_shared_pages_require_both_documents_to_acknowledge(): void
    {
        $source = $this->source();
        $v = $this->service->saveVersion($this->data($source, ['shared_pages' => true]), $this->user->id);
        $second = $this->service->saveVersion($this->data($source, ['instance' => 'shared', 'shared_pages' => true]), $this->user->id);
        $preview = app(DossierExportService::class)->preview($this->machine, [], [$v->id, $second->id]);
        $this->assertCount(2, $preview['manifest'][0]['pages']);
        $this->assertCount(0, $preview['manifest'][1]['pages']);
        $this->assertCount(2, $preview['manifest'][1]['original_pages']);
    }

    public function test_future_replacement_current_expired_and_archived_do_not_fall_back(): void
    {
        $v = $this->service->saveVersion($this->data($this->source()), $this->user->id);
        $future = $this->service->saveVersion($this->data($this->source('replacement'), ['effective_at' => '2026-11-01 00:00:00', 'expiry_value' => '2026-11-03', 'reason' => 'Gia hạn']), $this->user->id);
        $engine = app(DossierStatus::class);
        $this->assertSame($v->id, $engine->current($v->record->fresh()->load('versions'))->id);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 00:00:00', 'Asia/Ho_Chi_Minh'));
        $record = $v->record->fresh()->load('versions');
        $this->assertSame($future->id, $engine->current($record)->id);
        $this->assertSame('expired', $engine->describe($engine->current($record))['status']);
        $this->service->archive($future, true, 'Lưu trữ thử', $this->user->id);
        $this->assertSame('archived', $engine->describe($engine->current($record->fresh()->load('versions')))['status']);
        $this->assertSame(2, DossierVersion::count());
        $this->service->archive($future, false, 'Khôi phục thử', $this->user->id);
        $this->assertFalse($future->fresh()->archived);
        $this->assertDatabaseHas('activity_logs', ['event' => 'dossier.restored']);
    }

    public function test_expiry_precision_and_vietnam_boundary_never_uses_certification_date(): void
    {
        $source = $this->source();
        $v = $this->service->saveVersion($this->data($source, ['expiry_value' => '2026-10-28', 'certified_on' => '2027-01-01']), $this->user->id);
        $engine = app(DossierStatus::class);
        $this->assertSame('expiring', $engine->describe($v, [30], CarbonImmutable::parse('2026-10-28 23:59:59', 'Asia/Ho_Chi_Minh'))['status']);
        $this->assertSame('expired', $engine->describe($v, [30], CarbonImmutable::parse('2026-10-29 00:00:00', 'Asia/Ho_Chi_Minh'))['status']);
        $v->expiry_precision = 'minute';
        $v->expiry_value = '2026-10-28 12:00:00';
        $this->assertSame('expiring', $engine->describe($v, [30])['status']);
        $this->assertSame('expired', $engine->describe($v, [30], $engine->now()->addSecond())['status']);
        $v->expiry_precision = 'month';
        $v->expiry_value = '2026-10';
        $v->month_rule = null;
        $this->assertSame('review', $engine->describe($v, [30])['status']);
        $v->month_rule = 'end_of_month';
        $this->assertSame('2026-10-31 23:59:59', $engine->describe($v, [30])['expires_at']);
        $v->expiry_precision = 'permanent';
        $this->assertSame('permanent', $engine->describe($v)['status']);
        $v->expiry_precision = 'unknown';
        $this->assertSame('review', $engine->describe($v)['status']);
    }

    public function test_two_drivers_and_transfer_one_preserves_the_other_and_reuses_personal_documents(): void
    {
        $a = Driver::create(['name' => 'Driver A']);
        $b = Driver::create(['name' => 'Driver B']);
        $this->service->assign($this->machine, $a, '2026-01-01 00:00:00', null, $this->user->id);
        $this->service->assign($this->machine, $b, '2026-01-01 00:00:00', null, $this->user->id);
        $type = DossierType::whereJsonContains('groups', 'driver')->firstOrFail();
        $v = $this->service->saveVersion($this->data($this->source(), ['subject' => 'driver', 'subject_id' => $a->id, 'type_id' => $type->id]), $this->user->id);
        $report = app(DossierReport::class);
        $this->assertCount(2, $report->drivers($this->machine));
        $history = MachineDriverHistory::where('driver_id', $a->id)->first();
        $this->post(route('dossiers.assign.close', $history), ['ended_at' => '2026-10-28T10:00'])->assertRedirect();
        $this->assertSame([$b->id], $report->drivers($this->machine)->pluck('driver_id')->all());
        $other = Machine::create(['asset_code' => 'T-OTHER', 'company' => 'VINCONS', 'chassis_no' => 'OTHER', 'status' => 'ACTIVE']);
        $this->service->assign($other, $a, '2026-10-28 10:00:00', null, $this->user->id);
        $this->assertTrue($report->rows($other)->contains(fn ($r) => $r['version']?->id === $v->id));
        $this->assertFalse($report->rows($this->machine)->contains(fn ($r) => $r['version']?->id === $v->id));
        $this->assertSame(1, DossierVersion::count());
    }

    public function test_profile_link_conflict_does_not_modify_mapping_histories_ocr_or_backlog(): void
    {
        $a = Driver::create(['name' => 'Same name']);
        $b = Driver::create(['name' => 'Same name']);
        ZaloMessage::create(['group_id' => 'fixture-group', 'message_id' => 'profile-message', 'sender_id' => 'sender-stable', 'sender_name' => 'Same name', 'sent_at' => now(), 'received_at' => now(), 'raw_payload' => []]);
        $tables = ['zalo_sender_driver_links', 'zalo_sender_machine_mappings', 'machine_driver_histories', 'ocr_jobs', 'daily_photo_recovery_runs'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);
        $this->post(route('dossiers.sender', $a), ['sender_id' => 'sender-stable'])->assertRedirect();
        $this->post(route('dossiers.sender', $b), ['sender_id' => 'sender-stable'])->assertSessionHasErrors();
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->get()->toJson());
        }
        $this->assertDatabaseHas('dossier_sender_profiles', ['sender_id' => 'sender-stable', 'driver_id' => $a->id]);
        $this->get(route('dossiers.driver', $a))->assertOk()->assertSee('sender-stable');
    }

    public function test_pdf_manifest_exact_order_versions_shared_rotation_and_private_download(): void
    {
        $source = $this->source();
        $v1 = $this->service->saveVersion($this->data($source, ['pages' => [['page' => 4, 'rotation' => 90], ['page' => 1, 'rotation' => 0]]]), $this->user->id);
        $v2 = $this->service->saveVersion($this->data($source, ['instance' => 'second', 'pages' => [['page' => 2, 'rotation' => 270], ['page' => 3, 'rotation' => 0]]]), $this->user->id);
        $service = app(DossierExportService::class);
        $uuid = (string) Str::uuid();
        $e = $service->create($this->machine, [], [$v2->id, $v1->id], $uuid, true, $this->user->id);
        (new BuildDossierExport($e->id))->handle(app(DossierPdf::class));
        (new BuildDossierExport($e->id))->handle(app(DossierPdf::class));
        $this->assertSame($e->id, $service->create($this->machine, [], [$v1->id], $uuid, true, $this->user->id)->id);
        $e->refresh();
        $this->assertSame('ready', $e->status);
        $this->assertSame([$v2->id, $v1->id], array_column($e->manifest, 'version_id'));
        $pdf = new Fpdi;
        $this->assertSame(4, $pdf->setSourceFile(Storage::disk('dossiers')->path($e->path)));
        $reader = new \setasign\Fpdi\PdfReader\PdfReader(new \setasign\Fpdi\PdfParser\PdfParser(
            \setasign\Fpdi\PdfParser\StreamReader::createByFile(Storage::disk('dossiers')->path($e->path))));
        $this->assertSame(270, $reader->getPage(1)->getRotation());
        $this->assertSame(90, $reader->getPage(3)->getRotation());
        $this->assertSame($source->sha256, hash_file('sha256', Storage::disk('dossiers')->path($source->path)));
        $this->get(route('dossiers.export.file', $e))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('dossiers.export.file', $e))->assertForbidden();
        auth()->logout();
        $this->get(route('dossiers.file', [$source, 'source']))->assertRedirect(route('login'));
        $this->get(route('dossiers.export.file', $e))->assertRedirect(route('login'));
        $this->assertSame(1, DossierExport::count());
    }

    public function test_excel_and_ui_have_same_missing_and_expired_counts_and_no_old_driver(): void
    {
        $source = $this->source();
        $v = $this->service->saveVersion($this->data($source, ['expiry_value' => '2026-10-01']), $this->user->id);
        $missing = DossierType::where('id', '!=', $v->record->type_id)->whereJsonContains('groups', 'machine')->first();
        $missing->update(['required_groups' => ['machine']]);
        $report = app(DossierReport::class);
        $counts = $report->summary($report->rows($this->machine));
        $this->assertSame(1, $counts['expired']);
        $this->assertSame(1, $counts['missing']);
        $sheets = (new DossierWorkbook)->sheets();
        $this->assertCount(2, $sheets);
        $this->assertCount(2, $sheets[1]->array());
        $this->assertSame(['Hết hạn', 'Thiếu giấy bắt buộc'], array_column($sheets[1]->array(), 14));
        $this->get(route('dossiers.index', ['status' => 'missing']))->assertOk()->assertSee($this->machine->asset_code)->assertSee('Thiếu giấy bắt buộc');
        $this->get(route('dossiers.excel'))->assertOk()->assertDownload();
        $this->get(route('dossiers.machine', $this->machine))->assertOk();
        $this->get(route('dossiers.types'))->assertOk();
    }

    public function test_reminders_empty_disabled_dedupe_and_replacement_stop_old_alerts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['telegram.bot_token' => 'fixture-token', 'dossiers.telegram_enabled' => true, 'dossiers.telegram_recipients' => []]);
        $v = $this->service->saveVersion($this->data($this->source(), ['expiry_value' => '2026-11-04']), $this->user->id);
        $reminders = app(DossierReminderService::class);
        $this->assertSame(0, $reminders->run());
        Http::assertNothingSent();
        config(['dossiers.telegram_recipients' => ['123']]);
        $this->assertSame(3, $reminders->run());
        $this->assertSame(0, $reminders->run());
        Http::assertSentCount(3);
        $new = $this->service->saveVersion($this->data($this->source('new'), ['effective_at' => '2026-10-28 00:00:00', 'expiry_precision' => 'permanent']), $this->user->id);
        $this->assertSame(0, $reminders->run());
        $this->assertDatabaseMissing('dossier_reminders', ['version_id' => $new->id]);
    }

    public function test_confirmed_metadata_correction_is_a_new_version_and_never_overwrites_old(): void
    {
        $source = $this->source();
        $first = $this->service->saveVersion($this->data($source), $this->user->id);
        $source->refresh();
        $this->service->saveSource($source, ['machine_id' => $this->machine->id, 'revision' => $source->revision, 'excluded_pages' => [2, 3], 'confirm' => true], $this->user->id);
        $this->get(route('dossiers.source', [$source, 'replace' => $first->id]))->assertOk()->assertSee('Phiên bản sửa thông tin');
        $new = $this->service->saveVersion($this->data($source, ['record_id' => $first->record_id, 'effective_at' => '2026-10-28 12:00:00', 'expiry_value' => '2026-12-01', 'reason' => 'Sửa hạn nhập nhầm']), $this->user->id);
        $this->assertSame('2026-11-10', $first->fresh()->expiry_value);
        $this->assertSame('2026-12-01', $new->expiry_value);
        $this->assertSame($first->record_id, $new->record_id);
        $this->assertFalse($source->fresh()->confirmed);
        $this->assertDatabaseHas('activity_logs', ['event' => 'dossier.source_reopened_for_revision']);
    }

    public function test_primary_driver_change_closes_only_primary_and_reuses_existing_colleague_history(): void
    {
        $a = Driver::create(['name' => 'Primary']);
        $b = Driver::create(['name' => 'Second']);
        $c = Driver::create(['name' => 'New primary']);
        $this->service->assign($this->machine, $b, '2026-01-01 00:00:00', null, $this->user->id);
        $this->service->assign($this->machine, $a, '2026-01-01 00:00:00', null, $this->user->id);
        $this->machine->update(['current_driver_id' => $a->id]);
        app(\App\Services\MachineService::class)->assignDriver($this->machine->id, $c->id, '2026-10-28 10:00:00');
        $this->assertSame([$b->id, $c->id], app(DossierReport::class)->drivers($this->machine)->pluck('driver_id')->all());
        app(\App\Services\MachineService::class)->assignDriver($this->machine->id, $b->id, '2026-10-28 11:00:00');
        $this->assertSame([$b->id], app(DossierReport::class)->drivers($this->machine)->pluck('driver_id')->all());
        $this->assertSame(1, MachineDriverHistory::where('driver_id', $b->id)->count());
    }

    public function test_wrong_assignment_scope_and_former_driver_cannot_be_exported(): void
    {
        $driver = Driver::create(['name' => 'Former']);
        $this->service->assign($this->machine, $driver, '2026-01-01 00:00:00', '2026-10-01 00:00:00', $this->user->id);
        $v = $this->service->saveVersion($this->data($this->source(), ['subject' => 'driver', 'subject_id' => $driver->id,
            'type_id' => DossierType::whereJsonContains('groups', 'driver')->first()->id]), $this->user->id);
        $this->post(route('dossiers.exports.create', $this->machine), ['request_id' => (string) Str::uuid(), 'action' => 'preview', 'driver_ids' => [$driver->id], 'version_ids' => [$v->id]])->assertSessionHasErrors();
        $project = \App\Models\Project::create(['name' => 'Fixture project']);
        $old = MachineAssignment::create(['machine_id' => $this->machine->id, 'project_id' => $project->id, 'time_in' => '2026-01-01', 'time_out' => '2026-10-01']);
        MachineAssignment::create(['machine_id' => $this->machine->id, 'project_id' => $project->id, 'time_in' => '2026-10-01']);
        $scoped = $this->service->saveVersion($this->data($this->source('assignment'), ['subject' => 'assignment', 'subject_id' => $old->id,
            'type_id' => DossierType::where('name', 'Quyết định giao việc')->firstOrFail()->id]), $this->user->id);
        $this->assertFalse(app(DossierReport::class)->rows($this->machine)->contains(fn ($r) => $r['version']?->id === $scoped->id));
        $this->expectException(ValidationException::class);
        app(DossierExportService::class)->preview($this->machine, [], [$scoped->id]);
    }

    public function test_stale_revision_unclassified_pages_duplicate_effective_and_immutable_confirmation_are_rejected(): void
    {
        $source = $this->source();
        $data = $this->data($source);
        $v = $this->service->saveVersion($data, $this->user->id);
        $bad = [
            fn () => $this->service->saveSource($source, ['machine_id' => $this->machine->id, 'revision' => $source->revision, 'excluded_pages' => [], 'confirm' => false], $this->user->id),
            fn () => $this->service->saveSource($source->fresh(), ['machine_id' => $this->machine->id, 'revision' => $source->fresh()->revision, 'excluded_pages' => [], 'confirm' => true], $this->user->id),
            fn () => $this->service->saveVersion([...$data, 'number' => 'overwrite'], $this->user->id, $v),
            fn () => $this->service->saveVersion($this->data($this->source('same-date')), $this->user->id),
        ];
        foreach ($bad as $operation) {
            try {
                $operation();
                $this->fail('Expected validation error');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('NO-1', $v->fresh()->number);
    }

    public function test_telegram_rejections_retry_bounded_and_ambiguous_network_does_not_resend(): void
    {
        config(['telegram.bot_token' => 'fixture', 'dossiers.telegram_enabled' => true, 'dossiers.telegram_recipients' => ['123']]);
        $v = $this->service->saveVersion($this->data($this->source(), ['expiry_value' => '2026-11-04']), $this->user->id);
        $v->record->type->update(['warning_days' => [7]]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 429)]);
        $reminders = app(DossierReminderService::class);
        $reminders->run();
        $reminders->run();
        $reminders->run();
        $reminders->run();
        Http::assertSentCount(3);
        $this->assertDatabaseHas('dossier_reminders', ['version_id' => $v->id, 'attempts' => 3, 'status' => 'failed']);
        config(['dossiers.telegram_recipients' => ['456']]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('synthetic timeout'));
        $reminders->run();
        $reminders->run();
        $this->assertDatabaseHas('dossier_reminders', ['version_id' => $v->id, 'recipient' => '456', 'attempts' => 1, 'status' => 'uncertain']);
    }

    public function test_explicit_subject_correction_archives_old_scope_and_preserves_original_metadata(): void
    {
        $source = $this->source();
        $driverA = Driver::create(['name' => 'A']);
        $driverB = Driver::create(['name' => 'B']);
        $data = $this->data($source, ['subject' => 'driver', 'subject_id' => $driverA->id,
            'type_id' => DossierType::whereJsonContains('groups', 'driver')->first()->id]);
        $old = $this->service->saveVersion($data, $this->user->id);
        $source->refresh();
        $this->service->saveSource($source, ['machine_id' => $this->machine->id, 'revision' => $source->revision, 'excluded_pages' => [2, 3], 'confirm' => true], $this->user->id);
        $new = $this->service->saveVersion([...$data, 'request_id' => (string) Str::uuid(), 'record_id' => $old->record_id, 'corrects_id' => $old->id,
            'subject_id' => $driverB->id, 'effective_at' => '2026-10-28 12:00:00', 'reason' => 'Xác minh giấy thuộc B'], $this->user->id);
        $this->assertTrue($old->fresh()->archived);
        $this->assertSame($driverA->id, $old->record->fresh()->driver_id);
        $this->assertSame($driverB->id, $new->record->driver_id);
        $this->assertSame($old->id, $new->corrects_id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'dossier.subject_corrected_archived']);
    }

    public function test_legacy_sender_identity_conflict_is_reported_without_changing_ocr_link(): void
    {
        $a = Driver::create(['name' => 'A']);
        $b = Driver::create(['name' => 'B']);
        ZaloMessage::create(['group_id' => 'fixture', 'message_id' => 'legacy-link', 'sender_id' => 'stable-id', 'sent_at' => now(), 'received_at' => now()]);
        $legacy = \App\Models\ZaloSenderDriverLink::create(['sender_id' => 'stable-id', 'driver_id' => $a->id, 'valid_from' => '2026-01-01 00:00:00', 'created_by' => $this->user->id]);
        $this->post(route('dossiers.sender', $b), ['sender_id' => 'stable-id'])->assertSessionHasErrors();
        $this->assertSame($a->id, $legacy->fresh()->driver_id);
        $this->assertDatabaseCount('dossier_sender_profiles', 0);
        $this->post(route('dossiers.sender', $a), ['sender_id' => 'stable-id'])->assertRedirect();
        $this->assertDatabaseHas('dossier_sender_profiles', ['driver_id' => $a->id]);
    }

    public function test_catalog_configuration_and_200_machine_report_use_bounded_queries(): void
    {
        $type = DossierType::first();
        $this->post(route('dossiers.types.save', $type), ['name' => 'Fixture mandatory', 'groups' => ['machine'], 'required_groups' => ['machine'],
            'expiry_mode' => 'review', 'warning_days_text' => '30,15,7', 'sort_order' => 5])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['machine'], $type->fresh()->required_groups);
        $machines = [];
        for ($i = 2; $i <= 200; $i++) {
            $machines[] = ['asset_code' => 'FIX-'.$i, 'company' => 'VINCONS', 'chassis_no' => 'FIX-'.$i, 'status' => 'ACTIVE'];
        }
        DB::table('machines')->insert($machines);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $report = app(DossierReport::class);
        $catalog = Machine::all();
        $report->warm($catalog);
        foreach ($catalog as $m) {
            $this->assertSame(1, $report->summary($report->rows($m))['missing']);
        }
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(20, $queries, '200-machine report should not query per machine/type');
    }

    public function test_existing_delete_routes_preserve_dossier_references_and_download_date_is_vietnam(): void
    {
        $source = $this->source();
        $driver = Driver::create(['name' => 'History protected']);
        $v = $this->service->saveVersion($this->data($source, ['subject' => 'driver', 'subject_id' => $driver->id,
            'type_id' => DossierType::whereJsonContains('groups', 'driver')->first()->id]), $this->user->id);
        $this->post(route('drivers.delete', $driver))->assertRedirect()->assertSessionHasErrors();
        $this->machine->update(['status' => 'RETURNED']);
        $this->post(route('machines.delete', $this->machine))->assertRedirect()->assertSessionHasErrors();
        $this->assertDatabaseHas('drivers', ['id' => $driver->id]);
        $this->assertDatabaseHas('machines', ['id' => $this->machine->id]);
        Storage::disk('dossiers')->assertExists($source->path);
        $machineVersion = $this->service->saveVersion($this->data($this->source('date-fixture')), $this->user->id);
        $e = app(DossierExportService::class)->create($this->machine, [], [$machineVersion->id], (string) Str::uuid(), true, $this->user->id);
        (new BuildDossierExport($e->id))->handle(app(DossierPdf::class));
        $e->update(['created_at' => '2026-10-27 18:01:00']);
        $this->get(route('dossiers.export.file', $e))->assertDownload('T-XX0769-2026-10-28.pdf');
    }

    public function test_module_permission_is_explicit_and_does_not_follow_registration_or_login(): void
    {
        $source = $this->source();
        $v = $this->service->saveVersion($this->data($source), $this->user->id);
        $export = app(DossierExportService::class)->create($this->machine, [], [$v->id], (string) Str::uuid(), true, $this->user->id);
        (new BuildDossierExport($export->id))->handle(app(DossierPdf::class));
        $this->get(route('dossiers.file', [$source, 'source']))->assertOk();
        $other = User::factory()->create();
        $this->actingAs($other);
        foreach (['dossiers.index', 'dossiers.types', 'dossiers.excel'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('dossiers.file', [$source, 'source']))->assertForbidden();
        $this->get(route('dossiers.file', [$source, 'thumbnail', 1]))->assertForbidden();
        $this->post(route('dossiers.batches.create'))->assertForbidden();
        config(['dossiers.allowed_user_ids' => []]);
        $this->actingAs($this->user)->get(route('dossiers.index'))->assertForbidden();
        config(['dossiers.allowed_user_ids' => [$other->id]]);
        $this->actingAs($other)->get(route('dossiers.index'))->assertOk();
        $this->get(route('dossiers.file', [$source, 'source']))->assertOk();
        $this->get(route('dossiers.export.file', $export))->assertForbidden();
    }

    public function test_pdf16_compressed_objects_are_normalized_automatically_without_changing_original_or_render(): void
    {
        $qpdf = getenv('DOSSIERS_QPDF') ?: 'C:/Program Files/PDF24/qpdf/bin/qpdf.exe';
        if (! is_file($qpdf) || ! is_file($this->poppler)) {
            $this->markTestSkipped('qpdf/Poppler required for mechanism test; this is not real-scan acceptance.');
        }
        config(['dossiers.qpdf' => $qpdf, 'dossiers.pdfinfo' => (getenv('DOSSIERS_PDFINFO') ?: dirname($this->poppler).'/pdfinfo'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))]);
        $disk = Storage::disk('dossiers');
        $inputFile = $this->pdf();
        $disk->put('mechanism-input.pdf', file_get_contents($inputFile->getRealPath()));
        (new \Symfony\Component\Process\Process([$qpdf, '--object-streams=generate', '--force-version=1.6', $disk->path('mechanism-input.pdf'), $disk->path('mechanism-compressed.pdf')]))->mustRun();
        $original = file_get_contents($disk->path('mechanism-compressed.pdf'));
        $this->assertStringStartsWith('%PDF-1.6', $original);
        $source = $this->service->upload($this->batch(), UploadedFile::fake()->createWithContent('T-XX0769.pdf', $original), $this->user->id)['source'];
        $job = new ProcessDossierSource($source->id);
        for ($i = 0; $i < 4; $i++) {
            $job->handle(app(DossierPdf::class));
        }
        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame(4, $source->page_count);
        $this->assertSame(hash('sha256', $original), hash_file('sha256', $disk->path($source->path)));
        $this->assertSame('qpdf-object-streams-disable', $source->processing['method']);
        $this->assertFalse($source->processing['rasterized']);
        $this->assertFalse($source->processing['downsampled']);
        foreach (range(1, 4) as $page) {
            $prefix = $disk->path('normalized-render-'.$page);
            (new \Symfony\Component\Process\Process([$this->poppler, '-f', (string) $page, '-l', (string) $page,
                '-singlefile', '-scale-to', '1000', '-png', $disk->path($source->processed_path), $prefix]))->mustRun();
            $this->assertSame(hash_file('sha256', $disk->path('thumbnails/'.$source->id.'/'.$page.'.png')), hash_file('sha256', $prefix.'.png'));
        }
        $hash = $source->processed_sha256;
        $job->handle(app(DossierPdf::class));
        $this->assertSame($hash, $source->fresh()->processed_sha256);
        $this->service->saveSource($source, ['machine_id' => $this->machine->id, 'revision' => $source->revision, 'excluded_pages' => [], 'confirm' => false], $this->user->id);
        $v = $this->service->saveVersion($this->data($source), $this->user->id);
        $e = app(DossierExportService::class)->create($this->machine, [], [$v->id], (string) Str::uuid(), true, $this->user->id);
        $this->assertSame($source->processed_sha256, $e->manifest[0]['processed_sha256']);
        (new BuildDossierExport($e->id))->handle(app(DossierPdf::class));
        $this->assertSame('ready', $e->fresh()->status);
        $this->get(route('dossiers.file', [$source, 'processed']))->assertOk();
    }

    public function test_recoverable_missing_xref_self_entry_warning_requires_validated_output_not_reupload(): void
    {
        config(['dossiers.qpdf' => getenv('DOSSIERS_QPDF') ?: 'C:/Program Files/PDF24/qpdf/bin/qpdf.exe', 'dossiers.pdfinfo' => (getenv('DOSSIERS_PDFINFO') ?: dirname($this->poppler).'/pdfinfo'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))]);
        $file = $this->missingXrefSelfEntry();
        $hash = hash_file('sha256', $file->getRealPath());
        $source = $this->service->upload($this->batch(), $file, $this->user->id)['source'];
        $source->update(['status' => 'failed', 'error' => 'previous qpdf warning']);
        $this->post(route('dossiers.source.retry', $source))->assertRedirect();
        (new ProcessDossierSource($source->id))->handle(app(DossierPdf::class));
        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame(1, $source->page_count);
        $this->assertSame(3, $source->processing['normalize_exit_code']);
        $this->assertSame(0, $source->processing['validation']['qpdf_check_exit_code']);
        $this->assertSame(1, $source->processing['validation']['trial_export_pages']);
        $this->assertSame($hash, hash_file('sha256', Storage::disk('dossiers')->path($source->path)));
        $this->assertDatabaseCount('dossier_sources', 1);
        $this->assertDatabaseCount('dossier_versions', 0);
        (new ProcessDossierSource($source->id))->handle(app(DossierPdf::class));
        $this->assertDatabaseCount('dossier_sources', 1);
    }

    public function test_warning_recovery_does_not_accept_exit2_unknown_warnings_or_failed_output_check(): void
    {
        config(['dossiers.qpdf' => getenv('DOSSIERS_QPDF') ?: 'C:/Program Files/PDF24/qpdf/bin/qpdf.exe', 'dossiers.pdfinfo' => (getenv('DOSSIERS_PDFINFO') ?: dirname($this->poppler).'/pdfinfo'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))]);
        $source = $this->service->upload($this->batch(), $this->missingXrefSelfEntry(), $this->user->id)['source'];
        foreach (['exit2', 'unknown_warning', 'output_warning'] as $failure) {
            $pdf = new class($failure) extends DossierPdf
            {
                public function __construct(private string $failure) {}

                protected function runTool(array $args, int $timeout, int $sourceId, string $stage): \Symfony\Component\Process\Process
                {
                    $real = parent::runTool($args, $timeout, $sourceId, $stage);
                    if (($stage === 'normalize' && $this->failure !== 'output_warning') || ($stage === 'check_processed' && $this->failure === 'output_warning')) {
                        $fake = \Mockery::mock(\Symfony\Component\Process\Process::class);
                        $fake->shouldReceive('getExitCode')->andReturn($this->failure === 'exit2' ? 2 : 3);
                        $fake->shouldReceive('getErrorOutput')->andReturn($real->getErrorOutput()."\nWARNING: unresolved unknown structural damage\n");

                        return $fake;
                    }

                    return $real;
                }
            };
            try {
                $pdf->prepare($source->fresh());
                $this->fail('Unsafe output accepted: '.$failure);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(match ($failure) {
                    'exit2' => 'mã 2', 'unknown_warning' => 'chưa được xác minh', default => 'không vượt kiểm tra',
                }, $e->getMessage());
            }
            $this->assertNull($source->fresh()->processed_path);
            $this->assertSame($source->sha256, hash_file('sha256', Storage::disk('dossiers')->path($source->path)));
        }
    }
}
