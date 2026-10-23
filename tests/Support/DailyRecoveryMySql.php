<?php

// Synthetic fixtures ONLY. Guarded localhost instance and a NEW p174_* database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('MMTB_RECOVERY_TEST_DB');
if (! preg_match('/^p174_[a-z0-9_]+$/', $database ?: '')) {
    throw new RuntimeException('Set a new p174_* fixture database');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$datadir = str_replace('\\', '/', $pdo->query('SELECT @@datadir')->fetchColumn());
if (! str_contains($datadir, '/phase17-3/mysql-data')) {
    throw new RuntimeException('Wrong isolated server');
}
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    $exists = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $exists->execute([$database]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('Setup requires a NEW database; never reset existing data');
    }
    $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33317', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/p174-no-config.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath(dirname(__DIR__, 2).'/storage/p174-fixtures');
@mkdir($app->storagePath('framework/views'), 0777, true);
@mkdir($app->storagePath('framework/cache'), 0777, true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['daily_photos.enabled' => true, 'ocr.max_attempts' => 3]);
Illuminate\Support\Facades\Http::preventStrayRequests();
use App\Models\DailyPhotoRecoveryRun;
use App\Models\Machine;
use App\Models\OcrJob;
use App\Models\User;
use App\Models\ZaloAttachment;
use App\Models\ZaloMessage;
use App\Models\ZaloSenderMachineMapping;
use App\Services\DailyPhotoRecoveryRunService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$statePath = $app->storagePath($database.'.json');
if ($mode === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    $actor = User::factory()->create();
    $machine = Machine::create(['asset_code' => 'VT-XX5259', 'chassis_no' => 'FIXTURE-'.$database, 'company' => 'VINCONS', 'status' => 'ACTIVE']);
    ZaloSenderMachineMapping::create(['sender_id' => 'native-fixture', 'active_sender_id' => 'native-fixture', 'machine_id' => $machine->id, 'valid_from' => '2026-09-01 00:00:00', 'source' => 'MANUAL_CORRECTION']);
    App\Models\MachineAssignment::create(['machine_id' => $machine->id, 'project_id' => App\Models\Project::create(['name' => 'Fixture project'])->id,
        'command_center_id' => App\Models\CommandCenter::create(['name' => 'Fixture BCH'])->id, 'time_in' => '2026-09-01 00:00:00']);
    App\Models\ReconciliationPeriod::create(['name' => 'Fixture period', 'type' => 'MONTHLY', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'status' => 'GENERATED']);
    for ($i = 0; $i < 92; $i++) {
        $message = ZaloMessage::create(['group_id' => 'fixture', 'message_id' => (string) Str::uuid(), 'sender_id' => 'native-fixture',
            'received_at' => '2026-09-10 06:01:00', 'sent_at' => '2026-09-10 06:00:00', 'status' => 'STORED']);
        $attachment = ZaloAttachment::create(['zalo_message_id' => $message->id, 'attachment_index' => 0, 'original_name' => 'synthetic.jpg', 'storage_disk' => 'local',
            'storage_path' => 'fixture/'.$i.'.jpg', 'sha256' => hash('sha256', $database.'-'.$i), 'mime_type' => 'image/jpeg', 'byte_size' => 5, 'status' => 'STORED']);
        OcrJob::create(['zalo_attachment_id' => $attachment->id, 'document_type' => 'DAILY_TIMEMARK', 'status' => 'EXCEPTION',
            'review_status' => $i === 91 ? 'APPROVED' : 'PENDING', 'reviewed_at' => $i === 91 ? now() : null,
            'extracted_date' => '2026-09-10', 'extracted_time' => $i === 89 || $i === 90 ? null : '06:15:00', 'attempts' => 1,
            'ocr_retry_attempts' => $i === 89 || $i === 90 ? 1 : 0, 'exceptions' => ['MISSING_ASSET_CODE']]);
    }
    $immutable = ['id', 'zalo_attachment_id', 'document_type', 'extracted_date', 'extracted_time', 'raw_text', 'attempts', 'reviewed_at', 'ocr_retry_attempts'];
    $state = ['actor' => $actor->id, 'request_id' => (string) Str::uuid(), 'source_digest' => hash('sha256', json_encode(DB::table('ocr_jobs')->orderBy('id')->get($immutable))),
        'protected_digest' => hash('sha256', json_encode(OcrJob::where('review_status', 'APPROVED')->first()->getAttributes()))];
    file_put_contents($statePath, json_encode($state));
    echo json_encode(['database' => $database, 'jobs' => 92, 'request_id' => $state['request_id']]).PHP_EOL;
    exit;
}
$state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
if (getenv('MMTB_RECOVERY_FIXTURE_CLOCK') === 'after-expiry') {
    \Carbon\Carbon::setTestNow(now()->addMinutes(3));
}
$gate = $argv[2] ?? null;
if ($gate) {
    $deadline = microtime(true) + 20;
    while (! is_file($gate)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Gate timeout');
        } usleep(10000);
    }
}
$started = hrtime(true);
$connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
if ($mode === 'dispatch') {
    // Real HTTP kernel, authenticated request, same form UUID in two independent PHP processes.
    auth()->loginUsingId($state['actor']);
    $request = Illuminate\Http\Request::create('/daily-photos/settings/recover', 'POST', ['sender_id' => 'native-fixture', 'request_id' => $state['request_id']]);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== 302) {
        throw new RuntimeException('Dispatch HTTP '.$response->getStatusCode());
    }
    echo json_encode(['http' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'connection' => $connection, 'ms' => (hrtime(true) - $started) / 1e6]).PHP_EOL;
} elseif ($mode === 'crash-committed') {
    app(DailyPhotoRecoveryRunService::class)->processNext($state['request_id']);
    echo 'committed recovery; deliberate process exit 77'.PHP_EOL;
    exit(77);
} elseif ($mode === 'crash-uncommitted') {
    DB::listen(function ($query) {
        if (str_starts_with(strtolower($query->sql), 'insert into `daily_photo_case_evidence`')) {
            echo 'crash after evidence INSERT inside transaction'.PHP_EOL;
            exit(78);
        }
    });
    app(DailyPhotoRecoveryRunService::class)->processNext($state['request_id']);
    throw new RuntimeException('Crash point not reached');
} elseif ($mode === 'work') {
    $held = false;
    $lockTimes = [];
    DB::listen(function ($query) use (&$held, &$lockTimes) {
        if (str_contains($query->sql, 'daily_photo_recovery_runs') && str_contains($query->sql, 'for update')) {
            $lockTimes[] = $query->time;
            if (! $held) {
                $held = true;
                DB::select('SELECT SLEEP(1)');
            }
        }
    });
    Artisan::call('ocr:process-daily-recovery-runs', ['--run' => $state['request_id'], '--steps' => 100]);
    echo json_encode(['connection' => $connection, 'ms' => (hrtime(true) - $started) / 1e6, 'max_run_lock_ms' => $lockTimes ? max($lockTimes) : null, 'counters' => DailyPhotoRecoveryRun::find($state['request_id'])->counters]).PHP_EOL;
} elseif ($mode === 'drain') {
    for ($i = 0; $i < 20; $i++) {
        Artisan::call('ocr:process-daily-recovery-runs', ['--run' => $state['request_id'], '--steps' => 100]);
        if (in_array(DailyPhotoRecoveryRun::find($state['request_id'])->status, ['COMPLETED', 'FAILED'], true)) {
            break;
        }
    }
    echo json_encode(DailyPhotoRecoveryRun::find($state['request_id'])->only(['status', 'counters', 'error_code'])).PHP_EOL;
} elseif (in_array($mode, ['benchmark', 'benchmark-background'], true)) {
    // Add unrelated machine-days in the same period to measure whole-period expansion.
    $period = App\Models\ReconciliationPeriod::first();
    $project = App\Models\Project::first();
    $bch = App\Models\CommandCenter::first();
    $rows = [];
    for ($m = 0; $m < 20; $m++) {
        $machine = Machine::create(['asset_code' => 'BENCH-'.$m, 'chassis_no' => 'BENCH-'.$database.'-'.$m, 'company' => 'VINCONS', 'status' => 'ACTIVE']);
        $assignment = App\Models\MachineAssignment::create(['machine_id' => $machine->id, 'project_id' => $project->id, 'command_center_id' => $bch->id, 'time_in' => '2026-09-01 00:00:00']);
        for ($d = 1; $d <= 30; $d++) {
            $rows[] = ['reconciliation_period_id' => $period->id, 'machine_id' => $machine->id, 'machine_assignment_id' => $assignment->id,
                'project_id' => $project->id, 'command_center_id' => $bch->id, 'work_date' => sprintf('2026-09-%02d', $d), 'segment_start' => '00:00:00', 'segment_end' => '23:59:59', 'status' => 'DRAFT'];
        }
    }
    DB::table('reconciliation_rows')->insert($rows);
    $times = ['analysis_ms' => 0.0, 'materialize_ms' => 0.0, 'pairing_ms' => 0.0, 'sync_ms' => 0.0];
    foreach ([App\Services\DailyPhotoCaseService::class => 'materialize', App\Services\DailyPhotoPairingService::class => 'recomputeMany', App\Services\Reconciliation\DailyPhotoSyncService::class => 'sync'] as $class => $method) {
        $real = app($class);
        $metric = ['materialize' => 'materialize_ms', 'recomputeMany' => 'pairing_ms', 'sync' => 'sync_ms'][$method];
        $mock = Mockery::mock($class)->shouldIgnoreMissing($real);
        $mock->shouldReceive($method)->andReturnUsing(function (...$args) use ($real, $method, $metric, &$times) {
            $start = hrtime(true);
            try {
                return $real->$method(...$args);
            } finally {
                $times[$metric] += (hrtime(true) - $start) / 1e6;
            }
        });
        app()->instance($class, $mock);
    }
    $real = app(App\Services\DailyPhotoBacklogService::class);
    $backlog = Mockery::mock(App\Services\DailyPhotoBacklogService::class)->makePartial();
    // Real analyse call, using a fresh constructor with timed direct downstream services.
    $backlog = new class(app(App\Services\AssetCodeResolver::class), app(App\Services\DailyPhotoExceptionReason::class), app(App\Services\DailyPhotoCaseService::class), app(App\Services\DailyPhotoPairingService::class), app(App\Services\Reconciliation\DailyPhotoSyncService::class), app(App\Services\DailyPhotoStoredOcrExtractor::class), app(App\Services\DailyPhotoImageGate::class)) extends App\Services\DailyPhotoBacklogService
    {
        public float $analysisMs = 0;

        public function analyse(Illuminate\Support\Collection $jobs, array $filters = []): Illuminate\Support\Collection
        {
            $start = hrtime(true);
            try {
                return parent::analyse($jobs, $filters);
            } finally {
                $this->analysisMs += (hrtime(true) - $start) / 1e6;
            }
        }
    };
    if ($mode === 'benchmark-background') {
        app()->instance(App\Services\DailyPhotoBacklogService::class, $backlog);
        auth()->loginUsingId($state['actor']);
        $postStarted = hrtime(true);
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create('/daily-photos/settings/recover', 'POST', ['sender_id' => 'native-fixture', 'request_id' => $state['request_id']]));
        $times['post_ms'] = (hrtime(true) - $postStarted) / 1e6;
        $units = [];
        for ($i = 0; $i < 200; $i++) {
            $unitStart = hrtime(true);
            $worked = app(DailyPhotoRecoveryRunService::class)->processNext($state['request_id']);
            $units[] = (hrtime(true) - $unitStart) / 1e6;
            if (! $worked) {
                break;
            }
        }
        $times['max_unit_ms'] = max($units);
        $result = DailyPhotoRecoveryRun::findOrFail($state['request_id'])->only(['status', 'counters']);
    } else {
        $result = $backlog->recover(['sender_id' => 'native-fixture']);
    }
    $total = (hrtime(true) - $started) / 1e6;
    $times['analysis_ms'] = $backlog->analysisMs;
    $times['total_ms'] = $total;
    $times['mutation_and_other_ms'] = $total - array_sum(array_intersect_key($times, array_flip(['analysis_ms', 'materialize_ms', 'pairing_ms', 'sync_ms'])));
    echo json_encode(['scenario' => $mode.' 91 pending + 1 protected + 600 unrelated reconciliation rows', 'times' => $times, 'result' => $result]).PHP_EOL;
    Mockery::close();
} elseif ($mode === 'verify-repeat') {
    $digest = function () {
        $hashes = [];
        foreach (['ocr_jobs', 'daily_photo_cases', 'daily_photo_case_evidence', 'daily_photo_intervals',
            'reconciliation_rows', 'activity_logs', 'zalo_sender_machine_mappings', 'zalo_messages', 'zalo_attachments'] as $table) {
            $hashes[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()));
        }

        return $hashes;
    };
    $before = $digest();
    $second = app(DailyPhotoRecoveryRunService::class)->start(User::findOrFail($state['actor']), ['sender_id' => 'native-fixture']);
    for ($i = 0; $i < 20 && app(DailyPhotoRecoveryRunService::class)->processNext($second->id); $i++) {
    }
    if ($before !== $digest() || $second->fresh()->status !== 'COMPLETED' || $second->fresh()->counters['recovered'] !== 0) {
        throw new RuntimeException('Repeat changed business data/audit/source or did not finish');
    }
    echo json_encode(['repeat_status' => $second->fresh()->status, 'business_tables_unchanged' => count($before),
        'recovered' => $second->fresh()->counters['recovered'], 'audit_count' => DB::table('activity_logs')->count()]).PHP_EOL;
} elseif ($mode === 'verify') {
    $run = DailyPhotoRecoveryRun::findOrFail($state['request_id']);
    $data = ['status' => $run->status, 'counters' => $run->counters, 'evidence' => DB::table('daily_photo_case_evidence')->count(),
        'cases' => DB::table('daily_photo_cases')->count(), 'rows' => DB::table('reconciliation_rows')->count(),
        'protected' => OcrJob::where('review_status', 'APPROVED')->where('status', 'EXCEPTION')->count(),
        'manual' => OcrJob::where('review_status', 'PENDING')->where('status', 'EXCEPTION')->count(),
        'pending' => DB::table('daily_photo_recovery_work')->where('done', false)->count(), 'runs' => DailyPhotoRecoveryRun::count(),
        'failures' => $run->failures, 'lease_until' => $run->lease_until?->toISOString()];
    $immutable = ['id', 'zalo_attachment_id', 'document_type', 'extracted_date', 'extracted_time', 'raw_text', 'attempts', 'reviewed_at', 'ocr_retry_attempts'];
    if (hash('sha256', json_encode(DB::table('ocr_jobs')->orderBy('id')->get($immutable))) !== $state['source_digest']
        || hash('sha256', json_encode(OcrJob::where('review_status', 'APPROVED')->first()->getAttributes())) !== $state['protected_digest']) {
        throw new RuntimeException('Immutable fixture data changed');
    }
    echo json_encode($data).PHP_EOL;
    if ($run->status === 'COMPLETED' && ($data['evidence'] !== 89 || $data['cases'] !== 1 || $data['rows'] !== 1 || $data['protected'] !== 1 || $data['manual'] !== 2 || $data['pending'] !== 0 || $data['runs'] !== 1 || $run->counters['recovered'] !== 89)) {
        throw new RuntimeException('Native acceptance mismatch');
    }
}
