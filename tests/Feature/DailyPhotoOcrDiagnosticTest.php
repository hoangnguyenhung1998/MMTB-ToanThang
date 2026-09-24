<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyPhotoOcrDiagnosticService;
use App\Services\DailyPhotoStoredOcrExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyPhotoOcrDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_photos.enabled' => true]);
        Storage::fake('local');
    }

    public function test_stored_raw_extractor_supports_production_dates_and_safe_time_regions(): void
    {
        $this->machine('T-XL0303');
        $job = $this->exceptionJob(implode("\n\n", [
            "[0deg/asset]\nT-XL 0303",
            "[0deg/full]\nP21 Sep,2026 Mon\n06:22",
            "[180deg/time_date]\n21 Tháng 9,20265C\n06-57",
        ]));

        $result = app(DailyPhotoStoredOcrExtractor::class)->extract($job);

        $this->assertSame(['T-XL0303'], $result['machine_candidates']);
        $this->assertSame(['2026-09-21'], $result['date_candidates']);
        $this->assertSame(['06:22:00'], $result['time_candidates']);
        $this->assertSame('06:22:00', $result['time']);
        $this->assertFalse($result['time_conflict']);
    }

    public function test_numeric_date_is_only_selected_when_orientation_is_deterministic(): void
    {
        $extractor = app(DailyPhotoStoredOcrExtractor::class);

        $this->assertSame(['2026-09-21'], $extractor->parseText('09/21/2026')['dates']);
        $this->assertSame(['2026-08-25'], $extractor->parseText('25/08/2026')['dates']);
        $this->assertSame([], $extractor->parseText('08/09/2026')['dates']);
        $this->assertSame(['08/09/2026'], $extractor->parseText('08/09/2026')['ambiguous_dates']);
        $this->assertSame([], $extractor->parseText('phone 090-123-4567')['times']);
        $this->assertSame([], $extractor->parseText('plate 15-45')['times']);
        $this->assertSame(['15:45:00'], $extractor->parseText('15-45', true)['times']);
        $this->assertSame([], $extractor->parseText('24:99')['times']);
        $this->assertSame(['2026-09-21'], $extractor->parseText('21Sep,2026')['dates']);
        $this->assertSame([], $extractor->parseText('06-5724 Thang9,92026', true)['times']);
        $this->assertSame([], $extractor->parseText('06-5724 Thang9,92026', true)['dates']);
    }

    public function test_primary_zero_degree_timemark_beats_rotation_noise_but_same_tier_conflicts_fail_closed(): void
    {
        $this->machine('VT-LL0008');
        $winner = $this->exceptionJob(implode("\n\n", [
            "[0deg/time_date]\nVT-LL0008\n11:01\n21 Tháng 9,2026",
            "[0deg/left_overlay]\nVT-LL0008\n11:01\n21 Tháng 9,2026",
            "[0deg/lower_full]\nVT-LL0008\n11:01\n21 Tháng 9,2026",
            "[180deg/full]\nVT-LL0008\n10:11\n21 Tháng 9,2026",
        ]));
        $conflict = $this->exceptionJob(implode("\n\n", [
            "[0deg/time_date]\nVT-LL0008\n11:01\n21 Tháng 9,2026",
            "[0deg/left_overlay]\nVT-LL0008\n11:31\n21 Tháng 9,2026",
        ]));

        $winnerResult = app(DailyPhotoStoredOcrExtractor::class)->extract($winner);
        $conflictResult = app(DailyPhotoStoredOcrExtractor::class)->extract($conflict);

        $this->assertSame('VT-LL0008', $winnerResult['machine']);
        $this->assertSame('2026-09-21', $winnerResult['date']);
        $this->assertSame('11:01:00', $winnerResult['time']);
        $this->assertFalse($winnerResult['time_conflict']);
        $this->assertNull($conflictResult['time']);
        $this->assertTrue($conflictResult['time_conflict']);
    }

    public function test_diagnostic_aggregates_all_jobs_limits_samples_and_does_not_mutate(): void
    {
        $this->machine('T-3C0172');
        $recoverable = $this->exceptionJob("[0deg/full]\nT-3C 0172\n11:02\n09/21/2026", [
            'observed_asset_code' => 'T-3C0172',
            'asset_code' => 'T-3C0172',
            'extracted_time' => '11:02:00',
            'exceptions' => ['CAPTURE_DATE_MISSING', 'OCR_RETRY_FAILED'],
        ]);
        $this->exceptionJob("[0deg/full]\nunreadable overlay", [
            'exceptions' => ['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING'],
        ]);
        $before = OcrJob::query()->orderBy('id')->get()->map->getAttributes();

        $report = app(DailyPhotoOcrDiagnosticService::class)->diagnose(['limit' => 1]);

        $this->assertSame(2, $report['total']);
        $this->assertCount(1, $report['samples']);
        $this->assertSame('READY_TO_MATERIALIZE', app(DailyPhotoOcrDiagnosticService::class)
            ->diagnoseJob($recoverable->fresh(['attachment.message']))['loss_stage']);
        $this->assertSame(1, $report['by_loss_stage']['READY_TO_MATERIALIZE']);
        $this->assertSame(1, $report['by_loss_stage']['MAPPING_MISSING']);
        $this->assertEquals($before, OcrJob::query()->orderBy('id')->get()->map->getAttributes());

        $this->artisan('ocr:daily-exception-diagnose --limit=1')->assertSuccessful();
        $this->assertEquals($before, OcrJob::query()->orderBy('id')->get()->map->getAttributes());
    }

    private function machine(string $assetCode): Machine
    {
        return Machine::query()->create([
            'asset_code' => $assetCode,
            'chassis_no' => 'CHASSIS-'.Str::uuid(),
            'company' => 'VINCONS',
            'status' => 'ACTIVE',
        ]);
    }

    private function exceptionJob(string $rawText, array $extra = []): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'ocr-diagnostic',
            'message_id' => (string) Str::uuid(),
            'sender_id' => 'diagnostic-sender',
            'sender_name' => 'Diagnostic Sender',
            'sent_at' => '2026-09-21 06:00:00',
            'received_at' => '2026-09-21 06:01:00',
            'status' => 'STORED',
        ]);
        $path = 'test/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'image');
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'image.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', $path),
            'mime_type' => 'image/jpeg',
            'byte_size' => 5,
            'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'document_type' => 'DAILY_TIMEMARK',
            'status' => 'EXCEPTION',
            'review_status' => 'PENDING',
            'attempts' => 1,
            'confidence' => 0.99,
            'raw_text' => $rawText,
            ...$extra,
        ]);
    }
}
