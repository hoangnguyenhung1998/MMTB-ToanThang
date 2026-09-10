<?php

namespace Tests\Feature;

use App\Models\CommandCenter;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\OcrJob;
use App\Models\Project;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\DailyImageArchiveService;
use App\Services\DailyPhotoCaseService;
use App\Services\DailyPhotoPairingService;
use App\Services\OcrReviewService;
use App\Services\Reconciliation\DailyPhotoResyncService;
use App\Services\Reconciliation\DailyPhotoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticDailyPhotoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_photos.enabled' => true]);
        Storage::fake('local');
    }

    public function test_ocr_completion_automatically_creates_row_and_uses_existing_rounding_hc_ot(): void
    {
        [$period, $row] = $this->fixture('VT-XL1137');
        $row->delete();
        foreach (['06:55', '11:10', '13:28', '17:31'] as $time) {
            $this->completePhoto($row, $time);
        }
        $fresh = $period->rows()->sole();
        $this->assertCount(2, $fresh->daily_intervals);
        $this->assertSame(420, (int) $fresh->regular_minutes);
        $this->assertSame(60, (int) $fresh->ot_afternoon_minutes);
        $this->assertSame('07:00', substr($fresh->regular_morning_start, 0, 5));
        $this->assertSame('16:30', substr($fresh->regular_afternoon_end, 0, 5));
        $this->assertSame(['06:55', '11:10', '13:28', '17:31'], app(DailyPhotoSyncService::class)->evidenceTimes($fresh)->all());
        $this->assertNull($fresh->manually_edited_at);
        $this->assertNull($fresh->reviewed_at);
    }

    public function test_three_marks_use_first_interval_and_keep_unmatched_point(): void
    {
        [$period, $row] = $this->fixture('VT-XL1137');
        $row->delete();
        foreach (['06:15', '11:10', '13:30'] as $time) {
            $this->completePhoto($row, $time);
        }
        $fresh = $period->rows()->sole();
        $this->assertSame('DAILY_PARTIAL', $fresh->evidence_status);
        $this->assertSame(270, (int) $fresh->regular_minutes);
        $this->assertCount(1, $fresh->daily_intervals);
        $this->assertNull($fresh->regular_afternoon_end);
        $this->assertNull($fresh->ot_afternoon_minutes);
        $this->assertSame(['06:15', '11:10', '13:30'], app(DailyPhotoSyncService::class)->evidenceTimes($fresh)->all());
    }

    public function test_one_unreviewed_mark_creates_row_without_end_or_allocation(): void
    {
        [$period, $row] = $this->fixture('VT-XL1137');
        $job = $this->photo($row, '06:15');
        OcrJob::query()->whereKey($job->id)->update(['review_status' => 'PENDING']);
        $row->delete();
        app(DailyPhotoSyncService::class)->sync($period);
        $fresh = $period->rows()->sole();
        $this->assertSame('DAILY_PARTIAL', $fresh->evidence_status);
        $this->assertNull($fresh->regular_minutes);
        $this->assertNull($fresh->confirmed_check_out);
        $this->assertNull($fresh->ocr_check_out_raw);
        $this->assertSame(['06:15'], app(DailyPhotoSyncService::class)->evidenceTimes($fresh)->all());
    }

    public function test_scoped_button_resync_is_idempotent_and_protects_manual_rows(): void
    {
        [$period, $row] = $this->fixture('VT-XL1137');
        [, $other] = $this->fixture('T-XL0345', $period);
        $this->photos($row, ['06:15', '11:10']);
        $otherJob = $this->photo($other, '07:00');
        app(DailyPhotoCaseService::class)->detach($otherJob);
        $outside = $this->photo($row, '15:00');
        $outside->update(['extracted_date' => '2026-10-01']);
        app(DailyPhotoCaseService::class)->detach($outside);
        $row->update(['manually_edited_at' => now(), 'regular_minutes' => 123, 'status' => 'REVIEWED']);
        $url = route('reconciliation-periods.allocate-times', $period);
        $this->actingAs(User::factory()->create())->post($url, ['command_center_id' => $row->command_center_id])
            ->assertRedirect()->assertSessionHas('success');
        $signature = $row->fresh()->evidence_signature;
        $this->post($url, ['command_center_id' => $row->command_center_id])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(123, (int) $row->fresh()->regular_minutes);
        $this->assertSame('REVIEWED', $row->fresh()->status);
        $this->assertTrue($row->fresh()->has_evidence_changes);
        $this->assertSame($signature, $row->fresh()->evidence_signature);
        $this->assertNull($otherJob->fresh()->daily_photo_case_id);
        $this->assertNull($outside->fresh()->daily_photo_case_id);
        $this->assertNull($other->fresh()->evidence_synced_at);
        $this->assertSame(['06:15', '11:10'], app(DailyPhotoSyncService::class)->evidenceTimes($row->fresh())->all());
    }

    public function test_allocate_times_is_post_only_and_period_get_batches_canonical_evidence_without_resync_or_writes(): void
    {
        [$period, $row] = $this->fixture('VT-XL1137');
        $this->photo($row, '06:15');
        foreach (range(1, 30) as $day) {
            if ($day === 9) {
                continue;
            }
            ReconciliationRow::query()->create([
                'reconciliation_period_id' => $period->id,
                'machine_id' => $row->machine_id,
                'machine_assignment_id' => $row->machine_assignment_id,
                'project_id' => $row->project_id,
                'command_center_id' => $row->command_center_id,
                'work_date' => sprintf('2026-09-%02d', $day),
                'segment_start' => '00:00:00',
                'segment_end' => '23:59:59',
                'status' => 'DRAFT',
            ]);
        }

        $user = User::factory()->create();
        $this->mock(DailyPhotoResyncService::class, fn ($mock) => $mock->shouldNotReceive('sync'));
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($user)
            ->get(route('reconciliation-periods.allocate-times', $period))
            ->assertMethodNotAllowed();
        $this->get(route('reconciliation-periods.show', $period))
            ->assertOk()
            ->assertSee('06:15');

        $canonicalReads = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'daily_photo_cases')
            || str_contains($sql, 'daily_photo_case_evidence')
            || (str_contains($sql, 'from "ocr_jobs" where "document_type" =') && str_contains($sql, '"machine_id" in')));
        $canonicalWrites = $canonicalReads->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete)\b/', $sql) === 1);

        $this->assertLessThanOrEqual(4, $canonicalReads->count(), $canonicalReads->implode(PHP_EOL));
        $this->assertCount(0, $canonicalWrites, $canonicalWrites->implode(PHP_EOL));
    }

    public function test_period_resync_recomputes_canonical_pairing_once_after_materializing_all_evidence(): void
    {
        [$period, $row] = $this->fixture('VT-XL1137');
        $jobs = $this->photos($row, ['06:15', '11:10', '13:30', '17:30']);
        $jobs->each(fn (OcrJob $job) => app(DailyPhotoCaseService::class)->detach($job));
        $this->partialMock(DailyPhotoPairingService::class, fn ($mock) => $mock
            ->shouldReceive('recomputeMany')->once()->passthru());

        app(DailyPhotoResyncService::class)->sync($period);

        $this->assertDatabaseCount('daily_photo_case_evidence', 4);
        $this->assertDatabaseCount('daily_photo_intervals', 2);
    }

    public function test_resync_materializes_old_jobs_and_new_evidence_without_duplicate_rows_or_intervals(): void
    {
        [$period,$row] = $this->fixture('VT-XL1137');
        $job = $this->photo($row, '06:15');
        app(DailyPhotoCaseService::class)->detach($job);
        $row->delete();
        $service = app(\App\Services\Reconciliation\DailyPhotoResyncService::class);
        $this->assertSame(1, $service->sync($period)['updated']);
        $this->assertSame(0, $service->sync($period)['updated']);
        $this->photo($row, '11:10');
        $this->assertSame(1, $service->sync($period)['updated']);
        $id = \App\Models\DailyPhotoInterval::sole()->id;
        $this->assertSame(0, $service->sync($period)['updated']);
        $this->assertSame($id, \App\Models\DailyPhotoInterval::sole()->id);
        $this->assertSame(1, $period->rows()->count());
        $this->assertDatabaseCount('daily_photo_case_evidence', 2);
    }

    public function test_export_two_of_four_without_review_and_with_exact_missing_note(): void
    {
        [, $row] = $this->fixture('VT-XX0823');
        $jobs = $this->photos($row, ['06:15', '11:10']);
        OcrJob::query()->whereKey($jobs->pluck('id')->all())->update(['review_status' => 'PENDING']);
        $file = app(DailyImageArchiveService::class)->createZip(['date_from' => '2026-09-09', 'date_to' => '2026-09-09']);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file['path']));
        $this->assertSame(3, $zip->numFiles);
        $this->assertSame('09/09/2026 – VT-XX0823: thiếu 2 ảnh', $zip->getFromName('GHI-CHU-THIEU-ANH.txt'));
        $zip->close();
        unlink($file['path']);
    }

    public function test_export_keeps_odd_ambiguous_and_more_than_four_images(): void
    {
        [, $odd] = $this->fixture('VT-XL1137');
        [, $ambiguous] = $this->fixture('T-XL0345');
        [, $six] = $this->fixture('T-XL0400');
        $this->photo($odd, '06:15');
        $this->photos($ambiguous, ['07:00', '07:00']);
        $this->photos($six, ['07:00', '11:00', '13:30', '17:30', '18:00', '20:00']);
        $file = app(DailyImageArchiveService::class)->createZip(['date_from' => '2026-09-09', 'date_to' => '2026-09-09']);
        $zip = new \ZipArchive;
        $zip->open($file['path']);
        $this->assertSame(10, $zip->numFiles);
        $notes = $zip->getFromName('GHI-CHU-THIEU-ANH.txt');
        $this->assertStringContainsString('VT-XL1137: thiếu 3 ảnh', $notes);
        $this->assertStringContainsString('T-XL0345: thiếu 2 ảnh', $notes);
        $this->assertStringNotContainsString('T-XL0400', $notes);
        $zip->close();
        unlink($file['path']);
    }

    public function test_first_ocr_learns_mapping_different_ocr_wins_without_replacing_default_and_missing_ocr_falls_back(): void
    {
        [, $first] = $this->fixture('VT-XL1137');
        [, $other] = $this->fixture('T-XL0345');
        $a = $this->completePhoto($first, '06:15');
        $this->assertDatabaseHas('zalo_sender_machine_mappings', ['active_sender_id' => 'sender-1', 'machine_id' => $first->machine_id]);
        $b = $this->completePhoto($other, '07:00');
        $c = $this->completePhoto($other, '11:10', null);
        $this->assertSame($other->machine_id, $b->machine_id);
        $this->assertSame('IMAGE_ASSET', $b->machine_resolution_method);
        $this->assertNotContains('SENDER_MACHINE_CONFLICT', $b->exceptions ?? []);
        $this->assertSame($first->machine_id, $c->machine_id);
        $this->assertSame('SENDER_MAPPING', $c->machine_resolution_method);
        $this->assertNotNull($c->machine_resolution_metadata['sender_machine_mapping_id']);
        $this->assertDatabaseCount('zalo_sender_machine_mappings', 1);
    }

    public function test_manual_mapping_change_uses_receipt_time_and_never_remaps_old_evidence(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-09 08:00:00'));
        [, $first] = $this->fixture('VT-XL1137');
        [, $other] = $this->fixture('T-XL0400');
        $this->completePhoto($first, '06:15');
        $old = $this->completePhoto($first, '07:00', null);
        $delayed = $this->pendingPhoto($first, '07:30');
        $this->travelTo(\Carbon\Carbon::parse('2026-09-09 10:00:00'));
        $this->actingAs(User::factory()->create())->post(route('daily-photos.link'), ['sender_id' => 'sender-1', 'machine_id' => $other->machine_id])
            ->assertRedirect()->assertSessionHas('success');
        $delayedResult = $this->finishPhoto($delayed, '07:30', null);
        $new = $this->completePhoto($other, '11:10', null);
        $this->assertSame($first->machine_id, $delayedResult->machine_id);
        $this->assertSame($other->machine_id, $new->machine_id);
        app(\App\Services\Reconciliation\DailyPhotoResyncService::class)->sync($first->period);
        $this->assertSame($first->machine_id, $old->fresh()->machine_id);
        $resolved = app(\App\Services\DailyPhotoMachineResolutionService::class)->resolve($old->fresh(), null, '2026-09-09', '07:00');
        $this->assertSame($first->machine_id, $resolved['machine']->id);
        $this->assertDatabaseCount('zalo_sender_machine_mappings', 2);
        $this->assertDatabaseCount('zalo_sender_driver_links', 0);
        $this->travelBack();
    }

    public function test_manual_evidence_correction_has_provenance_and_survives_mapping_change(): void
    {
        [, $first] = $this->fixture('VT-XL1137');
        [, $other] = $this->fixture('T-XL0400');
        $job = $this->completePhoto($first, '06:15');
        $user = User::factory()->create();
        $corrected = app(OcrReviewService::class)->review($job, ['action' => 'correct', 'machine_id' => $other->machine_id], $user);
        app(\App\Services\ZaloSenderMachineService::class)->save('sender-1', $first->machine_id, $user->id);
        $resolution = app(\App\Services\DailyPhotoMachineResolutionService::class)->resolve($corrected->fresh(), 'VT-XL1137', '2026-09-09', '06:15');
        $this->assertSame($other->machine_id, $resolution['machine']->id);
        $this->assertSame('HUMAN', $resolution['method']);
        $this->assertSame($user->id, $resolution['metadata']['human_resolution']['resolved_by']);
    }

    public function test_settings_and_period_ui_remove_default_review_gate(): void
    {
        [$period,$row] = $this->fixture('VT-XL1137');
        $job = $this->photo($row, '06:15');
        $this->actingAs(User::factory()->create());
        $this->get(route('daily-photos.settings'))->assertOk()->assertSee('Máy mặc định')->assertDontSee('name="driver_id"', false)->assertDontSee('datetime-local');
        $this->get(route('reconciliation-periods.show', $period))->assertOk()->assertSee('Cập nhật ảnh hằng ngày')->assertSee('06:15')
            ->assertDontSee('Lưu &amp; xác nhận', false)->assertDontSee('Cần kiểm tra ca');
        $this->get(route('ocr-reviews.index'))->assertOk()->assertSee('Ảnh và ngoại lệ OCR')->assertDontSee('Duyệt đúng');
        $this->get(route('ocr-reviews.show', $job))->assertOk()->assertSee('Lưu chỉnh sửa')->assertDontSee('Duyệt đúng');
    }

    public function test_missing_capture_time_keeps_date_only_evidence_and_blank_hours(): void
    {
        [$period,$row] = $this->fixture('VT-XL1137');
        $job = $this->pendingPhoto($row, '06:15');
        $row->delete();
        $service = app(\App\Services\OcrJobService::class);
        $claimed = $service->claim('no-time', ['DAILY_TIMEMARK']);
        $completed = $service->complete($claimed, ['worker_id' => 'no-time', 'attempt' => $claimed->attempts, 'date' => '2026-09-09', 'asset_code' => 'VT-XL1137', 'confidence' => 0.99]);
        $this->assertSame('COMPLETED', $completed->status);
        $this->assertNotNull($completed->daily_photo_case_id);
        $this->assertNull($completed->dailyPhotoCaseEvidence->capture_datetime);
        $fresh = $period->rows()->sole();
        $this->assertNull($fresh->regular_minutes);
        $this->assertSame([], app(DailyPhotoSyncService::class)->evidenceTimes($fresh)->all());
        $this->assertSame('COLLECTING', $completed->dailyPhotoCase->status);
        $this->assertSame(0, $completed->dailyPhotoCase->intervals()->count());
        $file = app(DailyImageArchiveService::class)->createZip(['date_from' => '2026-09-09', 'date_to' => '2026-09-09']);
        $zip = new \ZipArchive;
        $zip->open($file['path']);
        $this->assertSame(2, $zip->numFiles);
        $zip->close();
        unlink($file['path']);
    }

    public function test_historical_unresolved_ocr_can_resync_from_its_own_code_without_learning_current_mapping(): void
    {
        [$period,$row] = $this->fixture('VT-XL1137');
        $job = $this->pendingPhoto($row, '06:15');
        OcrJob::query()->whereKey($job->id)->update(['status' => 'EXCEPTION', 'extracted_date' => '2026-09-09', 'extracted_time' => '06:15:00',
            'asset_code' => 'VT-XL1137', 'exceptions' => json_encode(['UNKNOWN_ASSET_CODE'])]);
        app(\App\Services\Reconciliation\DailyPhotoResyncService::class)->sync($period);
        $this->assertSame($row->machine_id, $job->fresh()->machine_id);
        $this->assertSame('IMAGE_ASSET', $job->fresh()->machine_resolution_method);
        $this->assertNotNull($job->fresh()->daily_photo_case_id);
        $this->assertDatabaseCount('zalo_sender_machine_mappings', 0);
    }

    public function test_low_confidence_ocr_never_learns_sender_mapping_or_hours(): void
    {
        [$period,$row] = $this->fixture('VT-XL1137');
        $job = $this->pendingPhoto($row, '06:15');
        $service = app(\App\Services\OcrJobService::class);
        $claimed = $service->claim('uncertain', ['DAILY_TIMEMARK']);
        $completed = $service->complete($claimed, ['worker_id' => 'uncertain', 'attempt' => $claimed->attempts, 'asset_code' => 'VT-XL1137',
            'date' => '2026-09-09', 'time' => '06:15:00', 'confidence' => 0.1]);
        $this->assertSame('EXCEPTION', $completed->status);
        $this->assertNull($completed->daily_photo_case_id);
        $this->assertDatabaseCount('zalo_sender_machine_mappings', 0);
        $this->assertNull($row->fresh()->regular_minutes);
    }

    public function test_existing_legacy_mapping_is_visible_and_retained_when_ocr_observes_another_machine(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-09 08:00:00'));
        [, $legacy] = $this->fixture('VT-XL1137');
        [, $other] = $this->fixture('T-XL0400');
        $job = $this->pendingPhoto($other, '06:15');
        $driver = \App\Models\Driver::create(['name' => 'Legacy driver']);
        \App\Models\ZaloSenderDriverLink::create(['sender_id' => 'sender-1', 'driver_id' => $driver->id, 'created_by' => User::factory()->create()->id, 'valid_from' => '2026-09-01 00:00:00']);
        \App\Models\MachineDriverHistory::create(['machine_id' => $legacy->machine_id, 'driver_id' => $driver->id, 'started_at' => '2026-09-01 00:00:00']);
        $this->actingAs(User::factory()->create())->get(route('daily-photos.settings'))->assertOk()->assertSee('Ánh xạ hiện có');
        $completed = $this->finishPhoto($job, '06:15', 'T-XL0400');
        $this->assertSame($other->machine_id, $completed->machine_id);
        $this->assertDatabaseHas('zalo_sender_machine_mappings', ['machine_id' => $legacy->machine_id, 'active_sender_id' => 'sender-1']);
        $this->assertDatabaseCount('zalo_sender_driver_links', 1);
        $this->assertDatabaseCount('machine_driver_histories', 1);
        $this->travelBack();
    }

    public function test_overlapping_mapping_history_fails_closed_without_legacy_fallback(): void
    {
        [, $row] = $this->fixture('VT-XL1137');
        $job = $this->pendingPhoto($row, '06:15');
        $at = $job->attachment->message->received_at;
        foreach ([1, 2] as $index) {
            \App\Models\ZaloSenderMachineMapping::create(['sender_id' => 'sender-1', 'machine_id' => $row->machine_id,
                'source' => 'MANUAL_CORRECTION', 'valid_from' => $at->copy()->subDay(), 'valid_to' => $at->copy()->addDay()]);
        }
        $completed = $this->finishPhoto($job, '06:15', null);
        $this->assertNull($completed->machine_id);
        $this->assertSame('AMBIGUOUS_MAPPING', $completed->machine_resolution_metadata['sender_resolution_status']);
        $this->assertNull($completed->daily_photo_case_id);
    }

    public function test_rejected_evidence_and_confirmed_period_cannot_be_resynced(): void
    {
        [$period,$row] = $this->fixture('VT-XL1137');
        $job = $this->photo($row, '06:15');
        app(OcrReviewService::class)->review($job, ['action' => 'reject'], User::factory()->create());
        $result = app(\App\Services\Reconciliation\DailyPhotoResyncService::class)->sync($period);
        $this->assertNull($job->fresh()->daily_photo_case_id);
        $this->assertNull($row->fresh()->regular_minutes);
        $this->assertCount(0, app(DailyImageArchiveService::class)->groups(['date_from' => '2026-09-09', 'date_to' => '2026-09-09']));
        $period->update(['status' => 'CONFIRMED']);
        $this->actingAs(User::factory()->create())->post(route('reconciliation-periods.allocate-times', $period))->assertForbidden();
        $this->assertSame('CONFIRMED', $period->fresh()->status);
    }

    public function test_confirmed_row_evidence_display_updates_while_provenance_is_untouched(): void
    {
        [$period,$row] = $this->fixture('VT-XL1137');
        $first = $this->photo($row, '06:15');
        app(DailyPhotoSyncService::class)->sync($period);
        $row->refresh()->update(['status' => 'CONFIRMED', 'regular_minutes' => 300]);
        $signature = $row->fresh()->evidence_signature;
        $used = $row->fresh()->daily_ocr_job_ids;
        $this->photo($row, '11:10');
        app(DailyPhotoSyncService::class)->sync($period);
        $this->assertSame(300, (int) $row->fresh()->regular_minutes);
        $this->assertSame('CONFIRMED', $row->fresh()->status);
        $this->assertSame($signature, $row->fresh()->evidence_signature);
        $this->assertSame($used, $row->fresh()->daily_ocr_job_ids);
        $this->assertSame(['06:15', '11:10'], app(DailyPhotoSyncService::class)->evidenceTimes($row->fresh())->all());
        $this->assertTrue($row->fresh()->has_evidence_changes);
    }

    public function test_export_keeps_available_originals_when_one_file_is_missing(): void
    {
        [, $row] = $this->fixture('VT-XX0823');
        $jobs = $this->photos($row, ['06:15', '11:10']);
        Storage::disk('local')->delete($jobs->last()->attachment->storage_path);
        $file = app(DailyImageArchiveService::class)->createZip(['date_from' => '2026-09-09', 'date_to' => '2026-09-09']);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file['path']));
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame('09/09/2026 – VT-XX0823: thiếu 3 ảnh', $zip->getFromName('GHI-CHU-THIEU-ANH.txt'));
        $zip->close();
        unlink($file['path']);
    }

    private function pendingPhoto(ReconciliationRow $row, string $time): OcrJob
    {
        $job = $this->photo($row, $time);
        app(DailyPhotoCaseService::class)->detach($job);
        OcrJob::query()->whereKey($job->id)->update(['status' => 'PENDING', 'review_status' => 'PENDING', 'machine_id' => null, 'asset_code' => null, 'extracted_date' => null, 'extracted_time' => null]);

        return $job->fresh();
    }

    private function completePhoto(ReconciliationRow $row, string $time, ?string $asset = 'DEFAULT'): OcrJob
    {
        return $this->finishPhoto($this->pendingPhoto($row, $time), $time, $asset === 'DEFAULT' ? $row->machine->asset_code : $asset);
    }

    private function finishPhoto(OcrJob $job, string $time, ?string $asset): OcrJob
    {
        $service = app(\App\Services\OcrJobService::class);
        $claimed = $service->claim('auto-photo-test', ['DAILY_TIMEMARK']);
        $this->assertSame($job->id, $claimed?->id);

        return $service->complete($claimed, ['worker_id' => 'auto-photo-test', 'attempt' => $claimed->attempts, 'date' => '2026-09-09', 'time' => $time.':00', 'asset_code' => $asset, 'confidence' => 0.99]);
    }

    private function fixture(string $assetCode, ?ReconciliationPeriod $period = null): array
    {
        $project = Project::query()->create(['name' => 'Dự án '.$assetCode]);
        $commandCenter = CommandCenter::query()->create(['name' => 'BCH '.$assetCode]);
        $machine = Machine::query()->create([
            'asset_code' => $assetCode,
            'chassis_no' => 'CHASSIS-'.$assetCode,
            'company' => 'VINCONS',
            'status' => 'ACTIVE',
            'returned_to_app' => true,
            'gps_file_added' => false,
        ]);
        $assignment = MachineAssignment::query()->create([
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'command_center_id' => $commandCenter->id,
            'time_in' => '2026-09-01 00:00:00',
        ]);
        $period ??= ReconciliationPeriod::query()->create([
            'name' => 'Tháng 9/2026',
            'type' => 'MONTHLY',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
            'status' => 'GENERATED',
        ]);
        $row = ReconciliationRow::query()->create([
            'reconciliation_period_id' => $period->id,
            'machine_id' => $machine->id,
            'machine_assignment_id' => $assignment->id,
            'project_id' => $project->id,
            'command_center_id' => $commandCenter->id,
            'work_date' => '2026-09-09',
            'segment_start' => '00:00:00',
            'segment_end' => '23:59:59',
            'status' => 'DRAFT',
        ]);

        return [$period, $row];
    }

    private function photos(ReconciliationRow $row, array $times): \Illuminate\Support\Collection
    {
        return collect($times)->map(fn (string $time) => $this->photo($row, $time));
    }

    private function photo(ReconciliationRow $row, string $time, ?string $fingerprint = null): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'canonical-downstream',
            'message_id' => (string) Str::uuid(),
            'sender_id' => 'sender-1',
            'sent_at' => '2026-09-09 '.$time.':00',
            'received_at' => now(),
            'status' => 'STORED',
        ]);
        $path = 'test/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'image-'.$time);
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id,
            'attachment_index' => 0,
            'original_name' => 'image.jpg',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'sha256' => hash('sha256', $path),
            'mime_type' => 'image/jpeg',
            'byte_size' => 100,
            'status' => 'STORED',
        ]);
        $nearDuplicateIds = $fingerprint === null ? [] : OcrJob::query()
            ->where('machine_id', $row->machine_id)
            ->whereDate('extracted_date', '2026-09-09')
            ->get()
            ->filter(fn (OcrJob $candidate) => data_get($candidate->daily_metadata, 'image_fingerprint') === $fingerprint)
            ->modelKeys();
        $job = OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id,
            'machine_id' => $row->machine_id,
            'asset_code' => $row->machine->asset_code,
            'document_type' => 'DAILY_TIMEMARK',
            'extracted_date' => '2026-09-09',
            'extracted_time' => $time.':00',
            'status' => 'COMPLETED',
            'review_status' => 'AUTO_APPROVED',
            'confidence' => 0.99,
            'daily_metadata' => [
                'image_fingerprint' => $fingerprint,
                'near_duplicate_ids' => $nearDuplicateIds,
            ],
            'attempts' => 1,
        ])->fresh();
        app(DailyPhotoCaseService::class)->materialize($job);

        return $job->fresh(['dailyPhotoCase', 'dailyPhotoCaseEvidence']);
    }
}
