<?php

// Native multi-process acceptance. Explicit isolated datadir guard, never uses application DB.
require __DIR__.'/../../vendor/autoload.php';
$root = getenv('P18_ACCEPTANCE_ROOT');
if (! $root || ! is_dir($root) || ! str_contains(str_replace('\\', '/', $root), '/.codex/visualizations/')) {
    throw new RuntimeException('Explicit private acceptance root required');
}
$dbName = 'p18_concurrency_20261029';
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33318', 'DB_DATABASE' => $dbName, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $k => $v) {
    putenv($k.'='.$v);
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath($root.'/concurrency-storage');
foreach (['framework/cache/data', 'framework/views', 'framework/sessions', 'logs'] as $p) {
    if (! is_dir(storage_path($p))) {
        mkdir(storage_path($p), 0700, true);
    }
}
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['telegram.enabled' => false, 'dossiers.telegram_enabled' => false, 'database.connections.mysql.url' => null, 'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 33318, 'database.connections.mysql.database' => $dbName, 'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '']);
DB::purge();
Http::preventStrayRequests();
Queue::fake();
$admin = new PDO('mysql:host=127.0.0.1;port=33318;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dir = strtolower(str_replace('\\', '/', $admin->query('SELECT @@datadir')->fetchColumn()));
if ($dir !== strtolower(str_replace('\\', '/', $root.'/mysql-isolated/'))) {
    throw new RuntimeException('Must use own isolated MySQL');
}
$mode = $argv[1] ?? '';
$round = $argv[2] ?? '';
$role = $argv[3] ?? '';
function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
function waitFile(string $path): void
{
    $until = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Barrier timeout');
        }usleep(20000);
    }
}
function dataFor(array $s, string $round): array
{
    $r = $s['rounds'][$round];

    return ['request_id' => $r['request_id'], 'source_id' => $r['source_id'], 'type_id' => 8, 'subject' => 'machine', 'subject_id' => $s['machine_id'], 'instance' => 'native', 'pages' => [['page' => 1, 'rotation' => 0]], 'shared_pages' => false, 'effective_at' => $r['effective_at'], 'expiry_precision' => 'permanent', 'reason' => 'Native '.$round, 'confirmed' => true];
}
if ($mode === 'setup') {
    check(! $admin->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$dbName'")->fetchColumn(), 'Database exists: preserve prior evidence');
    $admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    Artisan::call('migrate', ['--force' => true]);
    $user = App\Models\User::factory()->create();
    auth()->login($user);
    $m = App\Models\Machine::create(['asset_code' => 'P18-NATIVE', 'company' => 'VINCONS', 'chassis_no' => 'P18-NATIVE', 'status' => 'ACTIVE']);
    $s = ['machine_id' => $m->id, 'user_id' => $user->id, 'rounds' => []];
    foreach (['baseline', 'confirmA', 'confirmB', 'replaceA', 'replaceB', 'replaceDuringExport', 'replaceAfterExport', 'retry'] as $i => $name) {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 16);
        $pdf->Text(20, 30, 'NATIVE '.$name);
        $f = Illuminate\Http\UploadedFile::fake()->createWithContent('P18-NATIVE.pdf', $pdf->Output('S'));
        $batch = App\Models\DossierBatch::create(['id' => (string) Illuminate\Support\Str::uuid(), 'user_id' => $user->id]);
        $src = app(App\Services\Dossiers\DossierService::class)->upload($batch, $f, $user->id)['source'];
        $src->update(['status' => 'ready', 'progress' => 1, 'page_count' => 1, 'machine_confirmed' => true]);
        $hour = match ($name) {
            'baseline' => '00','confirmA','confirmB' => '01','replaceA' => '02','replaceB' => '03','replaceDuringExport' => '04','replaceAfterExport' => '05','retry' => '06'
        };
        $s['rounds'][$name] = ['source_id' => $src->id, 'request_id' => (string) Illuminate\Support\Str::uuid(), 'effective_at' => '2026-01-01 '.$hour.':00:00'];
    }
    $v = app(App\Services\Dossiers\DossierService::class)->saveVersion(dataFor($s, 'baseline'), $user->id);
    $s['baseline_id'] = $v->id;
    file_put_contents($root.'/concurrency-state.json', json_encode($s, JSON_PRETTY_PRINT));
    echo json_encode(['setup' => true, 'database' => $dbName]);
    exit;
}
$s = json_decode(file_get_contents($root.'/concurrency-state.json'), true);
auth()->loginUsingId($s['user_id']);
if ($mode === 'worker') {
    $gate = $root.'/native-'.$round;
    $conn = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($gate.'-'.$role.'.ready', getmypid().' '.$conn);
    waitFile($gate.'.go');
    if ($role === 'follower') {
        waitFile($gate.'.holding');
    }
    $start = microtime(true);
    $result = ['pid' => getmypid(), 'connection_id' => $conn, 'round' => $round, 'role' => $role];
    try {
        if ($role === 'leader') {
            DB::beginTransaction();
            if ($round === 'jobRetry') {
                App\Models\DossierExport::whereKey($s['export_id'])->lockForUpdate()->firstOrFail();
            } else {
                App\Models\Machine::whereKey($s['machine_id'])->lockForUpdate()->firstOrFail();
            }
        }
        $service = app(App\Services\Dossiers\DossierService::class);
        if ($round === 'confirm') {
            $v = $service->saveVersion(dataFor($s, $role === 'leader' ? 'confirmA' : 'confirmB'), $s['user_id']);
            $result['version_id'] = $v->id;
        } elseif ($round === 'replace') {
            $v = $service->saveVersion(dataFor($s, $role === 'leader' ? 'replaceA' : 'replaceB'), $s['user_id']);
            $result['version_id'] = $v->id;
        } elseif ($round === 'retry') {
            $v = $service->saveVersion(dataFor($s, 'retry'), $s['user_id']);
            $result['version_id'] = $v->id;
        } elseif ($round === 'replaceFirst' && $role === 'leader') {
            $v = $service->saveVersion(dataFor($s, 'replaceDuringExport'), $s['user_id']);
            $result['version_id'] = $v->id;
        } elseif ($round === 'exportFirst' && $role === 'follower') {
            $v = $service->saveVersion(dataFor($s, 'replaceAfterExport'), $s['user_id']);
            $result['version_id'] = $v->id;
        } elseif ($round === 'jobRetry') {
            (new App\Jobs\BuildDossierExport($s['export_id']))->handle(app(App\Services\Dossiers\DossierPdf::class));
            $result['export_id'] = $s['export_id'];
        } else {
            $id = $s['selected_version'];
            $e = app(App\Services\Dossiers\DossierExportService::class)->create(App\Models\Machine::findOrFail($s['machine_id']), [], [$id], $s['export_request'], true, $s['user_id']);
            $result['export_id'] = $e->id;
        }
        if ($role === 'leader') {
            file_put_contents($gate.'.holding', 'locked');
            usleep(1500000);
            DB::commit();
        }
        $result['status'] = 'ok';
    } catch (Illuminate\Validation\ValidationException $e) {
        if (DB::transactionLevel()) {
            DB::rollBack();
        }$result['status'] = 'rejected';
        $result['validation'] = array_values($e->errors());
    }
    $result['elapsed_ms'] = round((microtime(true) - $start) * 1000, 2);
    echo json_encode($result);
    exit;
}
if ($mode === 'prepare') {
    $record = App\Models\DossierRecord::where('machine_id', $s['machine_id'])->firstOrFail();
    $v = $record->versions()->where('confirmed', true)->latest('effective_at')->firstOrFail();
    $s['selected_version'] = $v->id;
    $s['export_request'] = (string) Illuminate\Support\Str::uuid();
    file_put_contents($root.'/concurrency-state.json', json_encode($s, JSON_PRETTY_PRINT));
    echo json_encode(['selected_version' => $v->id]);
    exit;
}
if ($mode === 'verify') {
    $record = App\Models\DossierRecord::with('versions')->where('machine_id', $s['machine_id'])->firstOrFail();
    $current = app(App\Services\Dossiers\DossierStatus::class)->current($record);
    check(App\Models\DossierRecord::count() === 1, 'Duplicate record');
    check($record->versions->where('confirmed', true)->pluck('effective_at')->unique()->count() === $record->versions->where('confirmed', true)->count(), 'Conflicting confirmed effective timestamps');
    check($record->versions->contains('id', $s['baseline_id']), 'Lost history');
    $previous = null;
    foreach ($record->versions->sortBy('effective_at') as $version) {
        $name = substr($version->reason, strlen('Native '));
        $expected = dataFor($s, $name);
        check($version->source_id === $expected['source_id'] && $version->request_id === $expected['request_id'] && $version->effective_at === $expected['effective_at'], 'Immutable version metadata changed');
        check($version->replaces_id === $previous, 'Broken replacement chain');
        $previous = $version->id;
    }
    check(App\Models\ActivityLog::where('event', 'dossier.version_confirmed')->count() === $record->versions->count(), 'Duplicate or missing confirmation audit');
    if ($round === 'confirm') {
        check($record->versions->count() === 2, 'Exactly one confirmation must win');
    }
    if ($round === 'replace') {
        check($record->versions->count() === 4, 'Both ordered replacements must survive');
    }
    if ($round === 'replaceFirst') {
        check(App\Models\DossierExport::count() === 0, 'Stale export should be rejected');
    }
    if ($round === 'exportFirst') {
        $e = App\Models\DossierExport::firstOrFail();
        check($e->manifest[0]['version_id'] === $s['selected_version'], 'Snapshot lost exact old version');
        check($current->id !== $s['selected_version'], 'Replacement did not activate');
        $s['export_id'] = $e->id;
        file_put_contents($root.'/concurrency-state.json', json_encode($s, JSON_PRETTY_PRINT));
    }
    if ($round === 'retry') {
        check(App\Models\DossierVersion::where('request_id', $s['rounds']['retry']['request_id'])->count() === 1, 'Retry duplicated version');
    }
    if ($round === 'jobRetry') {
        $e = App\Models\DossierExport::firstOrFail();
        check($e->status === 'ready', 'Export not ready');
        check($e->manifest[0]['version_id'] === $s['selected_version'], 'Job changed manifest');
        check(hash_equals($e->sha256, hash_file('sha256', Storage::disk('dossiers')->path($e->path))), 'Export hash mismatch');
        $pdf = new setasign\Fpdi\Fpdi;
        check($pdf->setSourceFile(Storage::disk('dossiers')->path($e->path)) === 1, 'Output PDF cannot be reopened');
        check(App\Models\ActivityLog::where('event', 'dossier.export_requested')->count() === 1, 'Export audit duplicated');
    }
    foreach (App\Models\DossierSource::all() as $source) {
        check(hash_equals($source->sha256, hash_file('sha256', Storage::disk('dossiers')->path($source->path))), 'Source mutated');
    }
    echo json_encode(['verified' => $round, 'versions' => $record->versions->count(), 'current_version' => $current->id, 'baseline_retained' => true, 'exports' => App\Models\DossierExport::count(), 'source_hashes_preserved' => true]);
}
