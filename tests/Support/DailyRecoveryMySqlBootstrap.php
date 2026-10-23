<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('MMTB_RECOVERY_TEST_DB');
if (! preg_match('/^p174_unit_[a-z0-9_]+$/', $database ?: '')) {
    throw new RuntimeException('NEW p174_unit_* schema required');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (! str_contains(str_replace('\\', '/', $pdo->query('SELECT @@datadir')->fetchColumn()), '/phase17-3/mysql-data')) {
    throw new RuntimeException('Wrong isolated server');
}
$exists = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
$exists->execute([$database]);
if ($exists->fetchColumn()) {
    throw new RuntimeException('Use a NEW schema; bootstrap never resets existing databases');
}
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
foreach (['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33317', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
    'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/p174-no-config.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

// Isolate fake disks/views from concurrent SQLite/native processes, not just the database.
$fixtureStorage = dirname(__DIR__, 2).'/storage/p174-fixtures/phpunit-'.getmypid();
foreach (['framework/views', 'framework/cache', 'framework/sessions', 'logs'] as $directory) {
    @mkdir($fixtureStorage.'/'.$directory, 0777, true);
}
putenv('LARAVEL_STORAGE_PATH='.$fixtureStorage);
$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $fixtureStorage;
