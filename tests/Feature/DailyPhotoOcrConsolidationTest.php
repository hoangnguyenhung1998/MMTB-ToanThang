<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\OcrRegressionCase;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyPhotoImageGate;
use App\Services\DailyPhotoParserAuditService;
use App\Services\DailyPhotoStoredOcrExtractor;
use App\Services\OcrRegressionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyPhotoOcrConsolidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_photos.enabled' => true]);
        Storage::fake('local');
    }

    public function test_canonical_stored_parser_supports_production_date_time_and_context_guards(): void
    {
        $parser = app(DailyPhotoStoredOcrExtractor::class);

        $this->assertSame(['10:32:00'], $parser->parseText('10.32', 'primary_timemark')['times']);
        $this->assertSame([], $parser->parseText('counter 20570.8', 'unknown')['times']);
        $this->assertSame(['06:57:00'], $parser->parseText('06-57', 'time_date')['times']);
        $this->assertSame(['06:57:00'], $parser->parseText('06:571', 'time_date')['times']);
        $this->assertSame([], $parser->parseText('54:62', 'primary_timemark')['times']);
        foreach (['26 Tháng 9,2026', '26 Tháng 9, 2026', '24 Tháng9,2026', '09 Sep,2026', '09 Sep, 2026'] as $raw) {
            $this->assertSame(['2026-09-'.(str_starts_with($raw, '24') ? '24' : (str_starts_with($raw, '09') ? '09' : '26'))], $parser->parseText($raw)['dates']);
        }
    }

    public function test_primary_fields_win_over_lower_rotation_noise(): void
    {
        $this->machine('VT-XI1154');
        $job = $this->job(implode("\n\n", [
            "[0deg/primary_timemark]\n10.32\n24 Tháng 9,2026\nMTS:VT-XI1154",
            "[180deg/full]\n10:52\n25 Tháng 9,2026",
        ]), '2026-09-24 11:00:00');

        $result = app(DailyPhotoStoredOcrExtractor::class)->extract($job);

        $this->assertSame('VT-XI1154', $result['machine']);
        $this->assertSame('2026-09-24', $result['date']);
        $this->assertSame('10:32:00', $result['time']);
        $this->assertFalse($result['date_conflict']);
        $this->assertFalse($result['time_conflict']);
    }

    public function test_hour_meter_gate_requires_multiple_non_brand_signals(): void
    {
        $gate = app(DailyPhotoImageGate::class);
        $strong = $gate->classifyText("CURTIS\nHOURS\n20570.8\n1/10\n10:52\n24 Tháng 9,2026");

        $this->assertSame('IGNORED_HOUR_METER', $strong['document_type']);
        $this->assertNull($gate->classifyText('HOURS only'));
        $this->assertNull($gate->classifyText("HOURS\n1/10\n24 Tháng 9,2026"));
    }

    public function test_parser_audit_is_read_only_and_finds_primary_date_loss(): void
    {
        $job = $this->job("[0deg/primary_timemark]\n06:12\n26 Tháng 9,2026\nTLU:0040", '2026-09-26 07:00:00');
        $before = $job->fresh()->getAttributes();

        $report = app(DailyPhotoParserAuditService::class)->audit(0, 10);

        $this->assertSame(1, $report['scanned']);
        $this->assertSame(1, $report['counts']['PRIMARY_HAS_DATE_BUT_FINAL_MISSING']);
        $this->assertEquals($before, $job->fresh()->getAttributes());
        $this->artisan('ocr:daily-parser-audit --sample-limit=1')->assertSuccessful();
        $this->assertEquals($before, $job->fresh()->getAttributes());
    }

    public function test_correction_can_create_verified_case_idempotently_and_runner_is_read_only(): void
    {
        $machine = $this->machine('VT-XL0362');
        $job = $this->job("[0deg/primary_timemark]\nMã TS 8VT-XL0362\n06:25\n21 Aug,2026", '2026-08-21 07:00:00');
        $user = User::factory()->create();
        $payload = [
            'action' => 'correct_and_add_case',
            'machine_id' => $machine->id,
            'extracted_date' => '2026-08-21',
            'extracted_time' => '06:25',
            'case_category' => 'DOWNSTREAM',
            'expected_disposition' => 'DAILY_TIMEMARK',
            'review_notes' => 'Production-shaped complete fields',
        ];

        $this->actingAs($user)->put(route('ocr-reviews.update', $job), $payload)->assertRedirect();
        $protectedBeforeDuplicate = $job->fresh()->getAttributes();
        $this->actingAs($user)->put(route('ocr-reviews.update', $job), $payload)->assertRedirect();

        $this->assertDatabaseCount('ocr_regression_cases', 1);
        $this->assertEquals($protectedBeforeDuplicate, $job->fresh()->getAttributes());
        $case = OcrRegressionCase::query()->sole();
        $this->assertSame('VERIFIED', $case->verification_status);
        $this->assertSame('VT-XL0362', $case->expected_machine);
        $sourceBefore = $job->fresh()->getAttributes();
        $result = app(OcrRegressionRunner::class)->run();
        $this->assertSame(1, $result['pass'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertEquals($sourceBefore, $job->fresh()->getAttributes());
        $this->artisan('ocr:case-regression')->assertSuccessful();
    }

    public function test_hour_meter_case_stores_expected_disposition_without_binary_copy(): void
    {
        $job = $this->job("[0deg/full]\nCURTIS\nHOURS\n20570.8\n1/10\n10:52\n24 Tháng 9,2026", '2026-09-24 11:00:00');
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('ocr-reviews.update', $job), [
            'action' => 'correct_and_add_case',
            'case_category' => 'HOUR_METER',
            'expected_disposition' => 'IGNORED_HOUR_METER',
        ])->assertRedirect();

        $this->assertDatabaseHas('ocr_jobs', ['id' => $job->id, 'document_type' => 'IGNORED_HOUR_METER']);
        $case = OcrRegressionCase::query()->sole();
        $this->assertSame('IGNORED_HOUR_METER', $case->expected_disposition);
        $this->assertArrayHasKey('attachment_path', $case->source_metadata);
        $this->assertArrayNotHasKey('image_binary', $case->input_snapshot);
        $this->assertSame(1, app(OcrRegressionRunner::class)->run()['pass']);
    }

    public function test_intentional_case_mismatch_reports_useful_failure_without_source_mutation(): void
    {
        $machine = $this->machine('VT-XL0362');
        $job = $this->job("[0deg/primary_timemark]\nVT-XL0362\n06:25\n21 Aug,2026", '2026-08-21 07:00:00');
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('ocr-reviews.update', $job), [
            'action' => 'correct_and_add_case', 'machine_id' => $machine->id,
            'extracted_date' => '2026-08-21', 'extracted_time' => '06:25',
            'case_category' => 'TIME_PARSE', 'expected_disposition' => 'DAILY_TIMEMARK',
        ]);
        OcrRegressionCase::query()->update(['expected_time' => '07:25:00']);
        $before = $job->fresh()->getAttributes();

        $result = app(OcrRegressionRunner::class)->run();

        $this->assertSame(1, $result['fail']);
        $this->assertContains('time', $result['failures'][0]['mismatches']);
        $this->assertEquals($before, $job->fresh()->getAttributes());
    }

    public function test_one_thousand_audit_jobs_and_verified_cases_use_bounded_queries(): void
    {
        $machine = $this->machine('VT-XI1154');
        $now = now();
        $messages = $attachments = $jobs = $cases = [];
        foreach (range(1, 1000) as $index) {
            $messages[] = ['group_id' => 'phase-16-10-10', 'message_id' => "audit-{$index}", 'sender_id' => 'audit-sender', 'sender_name' => 'Audit', 'sent_at' => $now, 'received_at' => $now, 'status' => 'STORED', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($messages, 200) as $chunk) {
            DB::table('zalo_messages')->insert($chunk);
        }
        foreach (DB::table('zalo_messages')->where('group_id', 'phase-16-10-10')->orderBy('id')->pluck('id') as $index => $messageId) {
            $attachments[] = ['zalo_message_id' => $messageId, 'attachment_index' => 0, 'original_name' => 'a.jpg', 'storage_disk' => 'local', 'storage_path' => "phase-16-10-10/{$index}.jpg", 'sha256' => hash('sha256', 'phase-16-10-10-'.$index), 'mime_type' => 'image/jpeg', 'byte_size' => 1, 'status' => 'STORED', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($attachments, 250) as $chunk) {
            DB::table('zalo_attachments')->insert($chunk);
        }
        foreach (DB::table('zalo_attachments')->where('storage_path', 'like', 'phase-16-10-10/%')->orderBy('id')->pluck('id') as $attachmentId) {
            $jobs[] = ['zalo_attachment_id' => $attachmentId, 'document_type' => 'DAILY_TIMEMARK', 'status' => 'EXCEPTION', 'review_status' => 'PENDING', 'attempts' => 1, 'confidence' => .9, 'raw_text' => "[0deg/primary_timemark]\n10:32\n26 Sep,2026", 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($jobs, 250) as $chunk) {
            DB::table('ocr_jobs')->insert($chunk);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $audit = app(DailyPhotoParserAuditService::class)->audit(0, 0);
        $this->assertSame(1000, $audit['scanned']);
        $this->assertLessThanOrEqual(60, count($queries), implode(PHP_EOL, $queries));

        $snapshot = json_encode(['raw_text' => "[0deg/primary_timemark]\nVT-XI1154\n10:32\n26 Sep,2026", 'received_at' => '2026-09-26T12:00:00+07:00']);
        foreach (range(1, 1000) as $index) {
            $cases[] = ['case_category' => 'TIME_PARSE', 'document_type' => 'DAILY_TIMEMARK', 'input_snapshot' => $snapshot, 'expected_machine' => $machine->asset_code, 'expected_date' => '2026-09-26', 'expected_time' => '10:32:00', 'expected_disposition' => 'DAILY_TIMEMARK', 'expected_status' => 'AUTO', 'verification_status' => 'VERIFIED', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($cases, 250) as $chunk) {
            DB::table('ocr_regression_cases')->insert($chunk);
        }
        $queries = [];
        $startedAt = microtime(true);
        $regression = app(OcrRegressionRunner::class)->run(0);
        $this->assertSame(1000, $regression['pass']);
        $this->assertLessThan(30, microtime(true) - $startedAt);
        $this->assertLessThanOrEqual(20, count($queries), implode(PHP_EOL, $queries));
    }

    private function machine(string $assetCode): Machine
    {
        return Machine::query()->create([
            'asset_code' => $assetCode,
            'chassis_no' => 'OCR-CONSOLIDATION-'.Str::uuid(),
            'company' => 'VINCONS',
            'status' => 'ACTIVE',
        ]);
    }

    private function job(string $rawText, string $receivedAt): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'ocr-consolidation', 'message_id' => (string) Str::uuid(),
            'sender_id' => 'ocr-consolidation-sender', 'sender_name' => 'OCR Test',
            'sent_at' => $receivedAt, 'received_at' => $receivedAt, 'status' => 'STORED',
        ]);
        $path = 'test/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'image');
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id, 'attachment_index' => 0,
            'original_name' => 'image.jpg', 'storage_disk' => 'local', 'storage_path' => $path,
            'sha256' => hash('sha256', $path), 'mime_type' => 'image/jpeg', 'byte_size' => 5, 'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'document_type' => 'DAILY_TIMEMARK', 'status' => 'EXCEPTION',
            'review_status' => 'PENDING', 'attempts' => 1, 'confidence' => .9,
            'raw_text' => $rawText,
        ]);
    }
}
