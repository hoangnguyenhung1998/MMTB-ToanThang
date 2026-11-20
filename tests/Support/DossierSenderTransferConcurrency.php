<?php

// Run only against a dedicated loopback MySQL instance and private test directory.
require __DIR__.'/../../vendor/autoload.php';

$root = realpath((string) getenv('P183_TEST_ROOT'));
if (! $root || ! str_contains(str_replace('\\', '/', $root), '/.codex/visualizations/')) {
    throw new RuntimeException('Private fixture root required');
}
$mysql = new PDO('mysql:host=127.0.0.1;port=33320', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$normal = fn ($path) => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
if ($normal($mysql->query('SELECT @@datadir')->fetchColumn()) !== $normal($root.'/mysql')) {
    throw new RuntimeException('Unexpected MySQL data directory');
}
$mode = $argv[1] ?? '';
if ($mode === 'shutdown') {
    $mysql->exec('SHUTDOWN');
    exit;
}
$db = 'p183_sender_transfer_v2';
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33320', 'DB_DATABASE' => $db,
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath($root.'/storage');
foreach (['framework/cache/data', 'framework/views', 'framework/sessions', 'logs'] as $directory) {
    if (! is_dir(storage_path($directory))) {
        mkdir(storage_path($directory), 0700, true);
    }
}
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.mysql.url' => null, 'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 33320,
    'database.connections.mysql.database' => $db, 'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '']);
DB::purge();
Http::preventStrayRequests();
Queue::fake();
$mapping = app(App\Services\ZaloSenderMachineService::class);
$statePath = $root.'/state.json';

if ($mode === 'setup') {
    $mysql->exec("CREATE DATABASE `$db`");
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Migration failed');
    }
    $user = App\Models\User::factory()->create();
    $driver = App\Models\Driver::create(['name' => 'Fixture concurrent']);
    $machines = [];
    foreach (['START', 'B', 'C'] as $code) {
        $machines[] = App\Models\Machine::create(['asset_code' => 'P183-'.$code, 'chassis_no' => 'P183-'.$code, 'company' => 'VINCONS', 'status' => 'ACTIVE'])->id;
    }
    foreach (['transfer-a', 'transfer-b'] as $sender) {
        App\Models\ZaloMessage::create(['group_id' => 'fixture', 'message_id' => $sender, 'sender_id' => $sender,
            'sent_at' => now()->subMinute(), 'received_at' => now()->subMinute(), 'raw_payload' => []]);
        app(App\Services\ZaloSenderDriverService::class)->link(['sender_id' => $sender, 'driver_id' => $driver->id, 'valid_from' => '2025-01-01 00:00:00'], $user->id);
        $mapping->save($sender, $machines[0], $user->id);
    }
    file_put_contents($statePath, json_encode(['machines' => $machines, 'user' => $user->id, 'driver' => $driver->id]));
    echo "Fixture ready\n";
    exit;
}
$state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
if ($mode === 'a' || $mode === 'b') {
    $destination = $state['machines'][$mode === 'a' ? 1 : 2];
    $start = microtime(true);
    DB::transaction(function () use ($mapping, $mode, $destination, $state, $root) {
        $mapping->save('transfer-'.$mode, $destination, $state['user']);
        if ($mode === 'a') {
            file_put_contents($root.'/a-has-lock', '1');
            usleep(2000000);
        }
    }, 3);
    echo json_encode(['pid' => getmypid(), 'connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
        'ms' => round((microtime(true) - $start) * 1000)]).PHP_EOL;
    exit;
}
$active = App\Models\ZaloSenderMachineMapping::whereNull('valid_to')->get();
$events = App\Models\ActivityLog::where('event', 'zalo.driver_transferred')->get();
if ($active->count() !== 1 || $active->first()->machine_id !== $state['machines'][2] || $events->count() !== 2 ||
    $events->pluck('properties.from_machine_id')->all() !== array_slice($state['machines'], 0, 2) ||
    App\Models\MachineDriverHistory::count() !== 0) {
    throw new RuntimeException('Unexpected active mapping or transfer audit');
}
echo json_encode(['active_mappings' => $active->count(), 'transfer_events' => $events->count(), 'legacy_histories_written' => 0]).PHP_EOL;
