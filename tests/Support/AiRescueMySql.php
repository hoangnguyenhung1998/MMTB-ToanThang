<?php

// Synthetic fixtures only. Never connect to the default application database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('MMTB_AI_TEST_DB');
if (! preg_match('/^p175_[a-z0-9_]+$/', $database ?: '')) {
    throw new RuntimeException('NEW p175_* schema required');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (! str_contains(str_replace('\\', '/', $pdo->query('SELECT @@datadir')->fetchColumn()), '/phase17-3/mysql-data')) {
    throw new RuntimeException('Wrong isolated server');
}
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    $q = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $q->execute([$database]);
    if ($q->fetchColumn()) {
        throw new RuntimeException('Never reset an existing schema');
    }
    $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33317', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/p175-no-config.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath(dirname(__DIR__, 2).'/storage/p174-fixtures');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
config(['daily_photos.enabled' => true]);
use App\Models\OcrJob;
use App\Services\DailyPhotoAiRescueBatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$statePath = $app->storagePath($database.'-ai.json');
if ($mode === 'setup') {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $actor = App\Models\User::factory()->create();
    $message = App\Models\ZaloMessage::create(['group_id' => 'synthetic-ai', 'message_id' => $database, 'sender_id' => 'ai-fixture', 'received_at' => '2026-09-01 07:00:00', 'sent_at' => '2026-09-01 07:00:00', 'status' => 'STORED']);
    Storage::disk('local')->put('p175-synthetic.jpg', 'synthetic original');
    for ($i = 0; $i < 2062; $i++) {
        $a = App\Models\ZaloAttachment::create(['zalo_message_id' => $message->id, 'attachment_index' => $i, 'original_name' => 'synthetic.jpg', 'storage_disk' => 'local', 'storage_path' => $i === 2 ? 'p175-missing.jpg' : 'p175-synthetic.jpg', 'sha256' => hash('sha256', 'fixture-'.$i), 'mime_type' => 'image/jpeg', 'byte_size' => 18, 'status' => 'STORED']);
        OcrJob::create(['zalo_attachment_id' => $a->id, 'document_type' => 'DAILY_TIMEMARK', 'status' => 'EXCEPTION', 'review_status' => $i < 2 ? 'APPROVED' : 'PENDING', 'reviewed_at' => $i < 2 ? now() : null, 'attempts' => 3, 'ocr_retry_attempts' => 1, 'exceptions' => ['MISSING_DATE', 'MISSING_TIME'], 'raw_text' => 'synthetic manual']);
    }
    auth()->login($actor);
    $preview = app(DailyPhotoAiRescueBatchService::class)->preview(['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING']);
    file_put_contents($statePath, json_encode(['actor' => $actor->id, 'token' => $preview['preview_token'], 'protected_hash' => hash('sha256', json_encode(DB::table('ocr_jobs')->whereIn('id', [1, 2])->orderBy('id')->get()))]));
    echo "fixture2062 ready\n";
    exit;
}
$state = json_decode(file_get_contents($statePath), true);
$actor = App\Models\User::findOrFail($state['actor']);
auth()->login($actor);
$batch = app(DailyPhotoAiRescueBatchService::class);
if ($mode === 'benchmark') {
    $times = [];
    $time = microtime(true);
    $count = $batch->selectionCount(['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING']);
    $times['count_ms'] = (microtime(true) - $time) * 1000;
    $time = microtime(true);
    $preview = $batch->preview(['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING']);
    $times['preview_ms'] = (microtime(true) - $time) * 1000;
    $time = microtime(true);
    $result = $batch->execute($preview['preview_token'], $actor);
    $times['execute_ms'] = (microtime(true) - $time) * 1000;
    echo json_encode(['count' => $count, 'preview_unique' => $preview['unique_photos'], 'eligible' => $preview['will_queue'], 'accepted_in_http' => (int) $result->fresh()->enqueued, 'timings' => $times]).PHP_EOL;
}

if (getenv('MMTB_AI_FIXTURE_CLOCK') === 'after-expiry') {
    \Carbon\Carbon::setTestNow(now()->addMinutes(3));
}
$gate = $argv[2] ?? null;
if ($gate) {
    $end = microtime(true) + 20;
    while (! is_file($gate)) {
        if (microtime(true) > $end) {
            throw new RuntimeException('Gate timeout');
        }usleep(10000);
    }
}
$run = App\Models\DailyPhotoAiRescueRun::first();
$service = app(App\Services\DailyPhotoAiRescueRunService::class);
$connection = DB::selectOne('SELECT CONNECTION_ID() as id')->id;
$app->make(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (Throwable $error) {
    fwrite(STDERR, json_encode(['exception' => $error::class, 'code' => $error->getCode(), 'driver_code' => $error instanceof Illuminate\Database\QueryException ? ($error->errorInfo[1] ?? null) : null]).PHP_EOL);
});
if ($mode === 'new-preview') {
    copy($statePath, $statePath.'.previous');
    $state['token'] = $batch->preview(['CAPTURE_DATE_MISSING', 'CAPTURE_TIME_MISSING'])['preview_token'];
    file_put_contents($statePath, json_encode($state));
    echo "new readonly preview for duplicate-race diagnosis\n";
}

if ($mode === 'dispatch') {
    $start = microtime(true);
    $request = Illuminate\Http\Request::create('/ocr-reviews/ai-rescue/execute', 'POST', ['preview_token' => $state['token']]);
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    if ($response->getStatusCode() !== 302) {
        throw new RuntimeException('HTTP '.$response->getStatusCode());
    }
    echo json_encode(['pid' => getmypid(), 'connection' => $connection, 'http' => 302, 'location' => $response->headers->get('Location'), 'ms' => (microtime(true) - $start) * 1000]).PHP_EOL;
} elseif ($mode === 'change') {
    OcrJob::findOrFail(4)->update(['review_status' => 'CORRECTED', 'reviewed_at' => now()]);
    $attachment = App\Models\ZaloAttachment::first()->replicate();
    $attachment->attachment_index = 2063;
    $attachment->sha256 = hash('sha256', 'late');
    $attachment->save();
    $late = OcrJob::findOrFail(5)->replicate();
    $late->zalo_attachment_id = $attachment->id;
    $late->save();
    echo "post-confirm protection changed and late image added\n";
} elseif ($mode === 'crash-uncommitted') {
    DB::listen(function ($q) {
        if (str_starts_with(strtolower($q->sql), 'insert into `daily_photo_ai_rescue_run_items`') && DB::table('daily_photo_ai_rescue_attempts')->count() > 0) {
            echo "exit78 after attempt+item insert inside TX\n";
            exit(78);
        }
    });
    for ($i = 0; $i < 10; $i++) {
        $service->processNext($run->id);
    }throw new RuntimeException('Crash not reached');
} elseif ($mode === 'crash-committed') {
    $service->processNext($run->id);
    echo "exit77 after committed enqueue unit\n";
    exit(77);
} elseif ($mode === 'work' || $mode === 'drain') {
    $held = false;
    $lockTimes = [];
    if ($mode === 'work') {
        DB::listen(function ($q) use (&$held, &$lockTimes) {
            if (str_contains($q->sql, 'daily_photo_ai_rescue_runs') && str_contains($q->sql, 'for update')) {
                $lockTimes[] = $q->time;
                if (! $held) {
                    $held = true;
                    DB::select('SELECT SLEEP(1)');
                }
            }
        });
    }
    $time = microtime(true);
    $maxUnit = 0;
    for ($i = 0; $i < ($mode === 'work' ? 50 : 2200); $i++) {
        $t = microtime(true);
        $service->processNext($run->id);
        $maxUnit = max($maxUnit, (microtime(true) - $t) * 1000);
        $run->refresh();
        if ($run->enqueue_done || $run->status === 'FAILED') {
            break;
        }
    }
    echo json_encode(['pid' => getmypid(), 'connection' => $connection, 'ms' => (microtime(true) - $time) * 1000, 'max_unit_ms' => $maxUnit, 'lock_wait_ms' => $lockTimes ? max($lockTimes) : null, 'progress' => $service->progress($run->fresh())]).PHP_EOL;
} elseif ($mode === 'mock-ocr') {
    // Real claim/complete lifecycle; local fake provider produces UNKNOWN, never any HTTP.
    $rescue = app(App\Services\DailyPhotoAiRescueService::class);
    $calls = 0;
    $time = microtime(true);
    while ($attempt = $rescue->claim('native-mock')) {
        $calls++;
        $payload = ['worker_id' => 'native-mock', 'attempt' => $attempt->attempts, 'provider' => 'local-mock', 'model' => 'fixture', 'prompt_version' => $attempt->prompt_version, 'schema_version' => $attempt->schema_version, 'result' => ['classification' => 'UNKNOWN', 'machine' => null, 'capture_date' => null, 'capture_time' => null, 'ambiguities' => ['synthetic']], 'raw_response' => '{}'];
        $rescue->complete($attempt, $payload);
        $rescue->complete($attempt, $payload); // Callback replay must be a no-op.
    }
    if ($calls !== 2058) {
        throw new RuntimeException('Unexpected mock call count '.$calls);
    }
    echo json_encode(['mock_provider_calls' => $calls, 'ms' => (microtime(true) - $time) * 1000, 'progress' => $service->progress($run->fresh())]).PHP_EOL;
} elseif ($mode === 'verify' || $mode === 'verify-final') {
    $run->refresh();
    $attempts = DB::table('daily_photo_ai_rescue_attempts')->count();
    $items = DB::table('daily_photo_ai_rescue_run_items')->where('run_id', $run->id)->count();
    if (App\Models\DailyPhotoAiRescueRun::count() !== 1 || $run->cursor !== $items || $run->enqueued !== $attempts) {
        throw new RuntimeException('run/item/cursor/attempt duplication or loss');
    }
    if ($state['protected_hash'] !== hash('sha256', json_encode(DB::table('ocr_jobs')->whereIn('id', [1, 2])->orderBy('id')->get()))) {
        throw new RuntimeException('Protected source changed');
    }
    if ($mode === 'verify-final' && (! $run->enqueue_done || $run->selected_count !== 2062 || $attempts !== 2058 || $run->skipped !== 4 || $items !== 2062 || DB::table('daily_photo_ai_rescue_attempts')->where('ocr_job_id', '>', 2062)->exists())) {
        throw new RuntimeException('Final scope/counters mismatch');
    }
    echo json_encode(['attempts' => $attempts, 'items' => $items, 'progress' => $service->progress($run)]).PHP_EOL;
}

if ($mode === 'work-cli') {
    $time = microtime(true);
    Illuminate\Support\Facades\Artisan::call('ocr:process-ai-rescue-runs', ['--run' => $run->id, '--steps' => 50]);
    echo json_encode(['cli' => 'ocr:process-ai-rescue-runs', 'ms' => (microtime(true) - $time) * 1000, 'progress' => $service->progress($run->fresh())]).PHP_EOL;
} elseif ($mode === 'poll') {
    $max = 0;
    $total = 0;
    $mock = Mockery::mock(App\Services\DailyPhotoBacklogService::class);
    $mock->shouldNotReceive('analyse', 'report');
    app()->instance(App\Services\DailyPhotoBacklogService::class, $mock);
    OcrJob::retrieved(function () {
        throw new RuntimeException('Polling must not hydrate OCR jobs');
    });
    for ($i = 0; $i < 20; $i++) {
        $time = microtime(true);
        $request = Illuminate\Http\Request::create('/ocr-reviews/ai-rescue/runs/'.$run->id, 'GET');
        $request->headers->set('Accept', 'application/json');
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Poll HTTP '.$response->getStatusCode());
        }
        $elapsed = (microtime(true) - $time) * 1000;
        $total += $elapsed;
        $max = max($max, $elapsed);
    }
    Mockery::close();
    echo json_encode(['poll20_mean_ms' => $total / 20, 'poll20_max_ms' => $max, 'ocr_jobs_hydrated' => 0]).PHP_EOL;
}

if ($mode === 'verify-schedule') {
    Illuminate\Support\Facades\Artisan::call('schedule:list');
    if (! str_contains(Illuminate\Support\Facades\Artisan::output(), 'ocr:process-ai-rescue-runs --steps=50')) {
        throw new RuntimeException('AI enqueue schedule missing');
    }
    echo "AI minute schedule registered PASS (not hosting cron execution)\n";
}
