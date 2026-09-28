<?php

namespace Tests\Feature;

use App\Models\DailyPhotoAiRescueAttempt;
use App\Models\OcrJob;
use App\Services\DailyPhotoAiRescueBatchService;
use App\Services\DailyPhotoAiRescueService;
use App\Services\DailyPhotoBacklogService;
use App\Services\DailyPhotoExceptionReason;
use App\Services\OcrReviewService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OcrReviewPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_uses_page_bounded_hydration_db_aggregates_and_no_ai_eligibility_scan(): void
    {
        config(['daily_photos.enabled' => true]);
        $now = now();
        $messages = [];
        $attachments = [];
        $jobs = [];
        foreach (range(1, 120) as $id) {
            $messages[] = [
                'id' => $id, 'group_id' => 'performance', 'message_id' => 'performance-'.$id,
                'sender_id' => 'sender-'.($id % 5), 'sender_name' => 'Performance Sender',
                'sent_at' => $now->copy()->subDay(), 'received_at' => $now->copy()->subDay(),
                'status' => 'STORED', 'created_at' => $now, 'updated_at' => $now,
            ];
            $attachments[] = [
                'id' => $id, 'zalo_message_id' => $id, 'attachment_index' => 0,
                'storage_disk' => 'local', 'storage_path' => 'missing/'.$id.'.jpg',
                'sha256' => hash('sha256', (string) $id), 'mime_type' => 'image/jpeg',
                'byte_size' => 100, 'status' => 'STORED', 'created_at' => $now, 'updated_at' => $now,
            ];
            $jobs[] = [
                'id' => $id, 'zalo_attachment_id' => $id, 'document_type' => 'DAILY_TIMEMARK',
                'status' => 'EXCEPTION', 'review_status' => 'PENDING', 'attempts' => 1,
                'exceptions' => json_encode(['CAPTURE_TIME_MISSING']),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('zalo_messages')->insert($messages);
        DB::table('zalo_attachments')->insert($attachments);
        DB::table('ocr_jobs')->insert($jobs);
        foreach (range(1, 5) as $attempt) {
            DailyPhotoAiRescueAttempt::query()->create([
                'ocr_job_id' => 120, 'zalo_attachment_id' => 120,
                'source_sha256' => hash('sha256', '120'), 'status' => 'COMPLETED',
                'attempts' => 1, 'prompt_version' => 'v1', 'schema_version' => 'v1',
                'final_resolution' => $attempt === 5 ? 'HUMAN_REQUIRED' : 'FAILED',
                'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15,
                'requested_at' => $now, 'processed_at' => $now,
            ]);
        }

        $this->mock(DailyPhotoBacklogService::class)->shouldNotReceive('report')->shouldNotReceive('analyse');
        $this->mock(DailyPhotoAiRescueService::class)->shouldNotReceive('eligibilityReason');
        $queries = 0;
        $sql = [];
        $retrieved = [];
        DB::listen(function (QueryExecuted $query) use (&$queries, &$sql): void {
            $queries++;
            $sql[] = strtolower($query->sql);
        });
        Event::listen('eloquent.retrieved: *', function (string $event, array $payload) use (&$retrieved): void {
            $class = $payload[0]::class;
            $retrieved[$class] = ($retrieved[$class] ?? 0) + 1;
        });

        $review = app(OcrReviewService::class);
        $jobs = $review->paginate([]);
        $dailyOverview = $review->dailyOverview(now()->toDateString());
        $machines = $review->machineOptions();
        $dashboard = app(DailyPhotoAiRescueBatchService::class)->dashboard();

        Model::preventLazyLoading();
        view('ocr-reviews.index', [
            'jobs' => $jobs,
            'statusCounts' => collect(),
            'reviewStatusCounts' => collect(),
            'dailyOverview' => $dailyOverview,
            'machines' => $machines,
            'filters' => [],
            'exceptionReasonLabels' => DailyPhotoExceptionReason::LABELS,
            'aiRescueDashboard' => $dashboard,
        ])->render();
        Model::preventLazyLoading(false);

        $this->assertLessThanOrEqual(30, $retrieved[OcrJob::class] ?? 0);
        $this->assertLessThanOrEqual(1, $retrieved[DailyPhotoAiRescueAttempt::class] ?? 0);
        $this->assertLessThanOrEqual(14, $queries, implode("\n", $sql));
        $this->assertTrue(collect($sql)->contains(fn (string $statement): bool => str_contains($statement, 'sum(prompt_tokens)')));
    }
}
