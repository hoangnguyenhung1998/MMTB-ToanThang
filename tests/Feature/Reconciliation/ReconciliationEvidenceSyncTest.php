<?php

namespace Tests\Feature\Reconciliation;

use App\Models\AiReconciliationJob;
use App\Models\JournalDocument;
use App\Models\JournalRow;
use App\Models\OcrJob;
use App\Models\ReconciliationPeriod;
use App\Models\ReconciliationRow;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Services\Reconciliation\ReconciliationEvidenceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconciliationEvidenceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_combines_reviewed_daily_images_journal_and_ai_result(): void
    {
        [$period, $row, $machineId] = $this->baseData();
        $dailyStart = $this->ocrJob($machineId, 'DAILY_TIMEMARK', '06:24');
        $dailyEnd = $this->ocrJob($machineId, 'DAILY_TIMEMARK', '18:02');
        [$journalJob, $journalRows] = $this->journal($machineId);

        $aiJob = AiReconciliationJob::query()->create([
            'machine_id' => $machineId,
            'work_date' => '2026-08-17',
            'status' => 'COMPLETED',
            'source_signature' => hash('sha256', 'evidence'),
        ]);
        $submission = $aiJob->submissions()->create([
            'submission_uuid' => (string) Str::uuid(),
            'outcome' => 'MATCHED',
            'summary' => 'Ảnh hằng ngày khớp với nhật trình.',
            'confidence' => 1,
            'agent_name' => 'mmtb-rules-engine',
            'submitted_at' => now(),
        ]);

        $result = app(ReconciliationEvidenceSyncService::class)->sync($period);

        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseHas('reconciliation_rows', [
            'id' => $row->id,
            'evidence_status' => 'MATCHED',
            'ocr_check_in_raw' => '06:24',
            'ocr_check_out_raw' => '18:02',
            'work_location' => 'PK5',
            'work_content' => "San gạt\nLu lèn",
            'regular_minutes' => 420,
            'ot_afternoon_minutes' => 60,
            'ai_reconciliation_job_id' => $aiJob->id,
            'ai_reconciliation_submission_id' => $submission->id,
            'has_evidence_changes' => false,
        ]);

        $fresh = $row->fresh();
        $this->assertSame([$dailyStart->id, $dailyEnd->id], $fresh->daily_ocr_job_ids);
        $this->assertSame($journalRows->pluck('id')->all(), $fresh->journal_row_ids);
        $this->assertSame($journalJob->id, $journalRows->first()->document->ocr_job_id);
    }

    public function test_new_evidence_does_not_overwrite_a_manually_edited_row(): void
    {
        [$period, $row, $machineId] = $this->baseData();
        $this->ocrJob($machineId, 'DAILY_TIMEMARK', '07:00');
        $this->ocrJob($machineId, 'DAILY_TIMEMARK', '17:00');
        $this->journal($machineId);
        $service = app(ReconciliationEvidenceSyncService::class);
        $service->sync($period);

        $row->refresh()->update([
            'work_content' => 'Nội dung anh đã sửa',
            'manually_edited_at' => now(),
        ]);
        $this->ocrJob($machineId, 'DAILY_TIMEMARK', '18:00');

        $result = $service->sync($period);

        $this->assertSame(1, $result['protected']);
        $this->assertSame(1, $result['changed']);
        $this->assertDatabaseHas('reconciliation_rows', [
            'id' => $row->id,
            'work_content' => 'Nội dung anh đã sửa',
            'has_evidence_changes' => true,
        ]);
    }

    private function baseData(): array
    {
        $projectId = DB::table('projects')->insertGetId(['name' => 'Dự án A']);
        $bchId = DB::table('command_centers')->insertGetId(['name' => 'BCH A']);
        $machineId = DB::table('machines')->insertGetId([
            'asset_code' => 'VT-XL0196', 'company' => 'VINALPHA', 'chassis_no' => 'EVIDENCE-196',
            'status' => 'ACTIVE', 'returned_to_app' => true, 'gps_file_added' => false,
        ]);
        $assignmentId = DB::table('machine_assignments')->insertGetId([
            'machine_id' => $machineId, 'project_id' => $projectId, 'command_center_id' => $bchId,
            'time_in' => '2026-08-01 00:00:00', 'time_out' => null,
        ]);
        $period = ReconciliationPeriod::query()->create([
            'name' => 'Đối chiếu tháng 08/2026', 'type' => 'MONTHLY',
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'status' => 'GENERATED',
        ]);
        $row = ReconciliationRow::query()->create([
            'reconciliation_period_id' => $period->id, 'machine_id' => $machineId,
            'machine_assignment_id' => $assignmentId, 'project_id' => $projectId,
            'command_center_id' => $bchId, 'work_date' => '2026-08-17',
            'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT',
        ]);

        return [$period, $row, $machineId];
    }

    private function ocrJob(int $machineId, string $type, string $time): OcrJob
    {
        $message = ZaloMessage::query()->create([
            'group_id' => 'group-1', 'message_id' => (string) Str::uuid(),
            'sender_id' => 'sender-1', 'sender_name' => 'Người vận hành',
            'sent_at' => '2026-08-17 07:00:00', 'received_at' => now(), 'status' => 'STORED',
        ]);
        $attachment = ZaloAttachment::query()->create([
            'zalo_message_id' => $message->id, 'attachment_index' => 0,
            'original_name' => Str::uuid().'.jpg', 'storage_disk' => 'local',
            'storage_path' => 'test/'.Str::uuid().'.jpg', 'sha256' => hash('sha256', Str::uuid()),
            'mime_type' => 'image/jpeg', 'byte_size' => 10, 'status' => 'STORED',
        ]);

        return OcrJob::query()->create([
            'zalo_attachment_id' => $attachment->id, 'machine_id' => $machineId,
            'document_type' => $type, 'status' => 'COMPLETED', 'review_status' => 'APPROVED',
            'reviewed_at' => now(),
            'extracted_date' => '2026-08-17', 'extracted_time' => $time,
            'asset_code' => 'VT-XL0196', 'work_location' => 'PK5',
        ]);
    }

    private function journal(int $machineId): array
    {
        $job = $this->ocrJob($machineId, 'WEEKLY_JOURNAL', '06:30');
        $document = JournalDocument::query()->create([
            'ocr_job_id' => $job->id, 'machine_id' => $machineId,
            'asset_code' => 'VT-XL0196', 'confidence' => 0.99,
        ]);
        $rows = collect([
            ['row_number' => 1, 'start_time' => '06:30', 'end_time' => '10:30', 'total_minutes' => 240, 'work_content' => 'San gạt'],
            ['row_number' => 2, 'start_time' => '14:00', 'end_time' => '18:00', 'total_minutes' => 240, 'work_content' => 'Lu lèn'],
        ])->map(fn (array $data) => JournalRow::query()->create([
            'journal_document_id' => $document->id, 'work_date' => '2026-08-17',
            'work_location' => 'PK5', 'confidence' => 0.99, ...$data,
        ]));

        return [$job, $rows];
    }
}
