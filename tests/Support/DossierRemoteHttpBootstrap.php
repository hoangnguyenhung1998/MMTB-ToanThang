<?php

require __DIR__.'/../../vendor/autoload.php';
$root = getenv('P18_ACCEPTANCE_ROOT');
if (! $root || ! is_dir($root) || ! str_contains(str_replace('\\', '/', $root), '/.codex/visualizations/')) {
    throw new RuntimeException('Private test evidence root required');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33319;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (strtolower(str_replace('\\', '/', $pdo->query('SELECT @@datadir')->fetchColumn())) !== strtolower(str_replace('\\', '/', $root.'/mysql-isolated/'))) {
    throw new RuntimeException('Wrong MySQL datadir');
}
$private = $root.'/http-test-config.json';
if (! is_file($private)) {
    throw new RuntimeException('Generate private test configuration first');
}
$test = json_decode(file_get_contents($private), true);
foreach (['APP_ENV' => 'testing', 'APP_URL' => 'http://127.0.0.1:8848', 'APP_DEBUG' => 'false', 'APP_KEY' => $test['app_key'], 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33319', 'DB_DATABASE' => 'p18_remote_20261030', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'array', 'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array'] as $k => $v) {
    putenv($k.'='.$v);
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->useStoragePath($root.'/concurrency-storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['dossiers.storage_disk' => 'dossiers', 'dossiers.worker.token' => $test['worker_token'], 'dossiers.ai.enabled' => true, 'dossiers.worker.allow_mock_results' => true, 'dossiers.allowed_user_ids' => [$test['user_id'] ?? 0], 'dossiers.telegram_enabled' => false, 'telegram.enabled' => false, 'dossiers.ai.auto_analyze' => false]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();
