<?php

/** LOCAL-only guarded upgrade. No API calls, no queue/service restarts. */
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

try {
    $args = getopt('', ['apply', 'backup-dir:', 'mysqldump:']);
    $c = config('database.connections.'.config('database.default'));
    if (! app()->environment('local') || config('database.default') !== 'mysql' || ($c['url'] ?? null) || $c['host'] !== '127.0.0.1' || (int) $c['port'] !== 3306 || $c['database'] !== 'mmtb') {
        throw new RuntimeException('LOCAL mysql identity/config guard rejected; no migration.');
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=mmtb;charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $meta = $pdo->query('SELECT @@datadir AS datadir,@@server_uuid AS uuid,@@hostname AS hostname')->fetch(PDO::FETCH_ASSOC);
    if (strtolower(str_replace('\\', '/', $meta['datadir'])) !== 'c:/laragon/data/mysql-8.4/' || ! is_readable($meta['datadir'].'auto.cnf') || ! str_contains(file_get_contents($meta['datadir'].'auto.cnf'), $meta['uuid']) || strcasecmp($meta['hostname'], gethostname()) !== 0) {
        throw new RuntimeException('Physical local identity guard rejected; no migration.');
    }
    if (! Schema::hasTable('dossier_sources') || ! Schema::hasColumn('dossier_sources', 'processed_path')) {
        throw new RuntimeException('Phase18 prerequisite migrations missing; do not apply other pending migrations automatically.');
    }
    $migration = '2026_10_30_000001_create_dossier_ai';
    if (DB::table('migrations')->where('migration', $migration)->exists()) {
        echo "Phase18.1 migration already applied; no writes.\n";
        exit(0);
    }
    if (! array_key_exists('apply', $args)) {
        echo "LOCAL identity verified. No writes. Use --apply with --backup-dir outside webroot to back up/verify and apply ONLY Phase18.1.\n";
        exit(0);
    }
    $dir = realpath($args['backup-dir'] ?? '');
    $webroot = realpath(base_path('..'));
    if (! $dir || ! is_writable($dir) || str_starts_with(strtolower(str_replace('\\', '/', $dir)).'/', strtolower(str_replace('\\', '/', $webroot)).'/')) {
        throw new RuntimeException('Backup directory must already exist and be writable OUTSIDE Laragon www/repository.');
    }
    if (DB::table('information_schema.tables')->where('TABLE_SCHEMA', $c['database'])->where('TABLE_TYPE', 'BASE TABLE')->where('ENGINE', '!=', 'InnoDB')->exists()) {
        throw new RuntimeException('Non-InnoDB tables need a separate consistent backup plan.');
    }
    $tables = array_map(fn ($r) => array_values((array) $r)[0], DB::select('SHOW TABLES'));
    $name = $dir.'/before-phase18-1-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
    $out = fopen($name, 'xb');
    $bin = $args['mysqldump'] ?? 'mysqldump';
    $process = new Process([$bin, '--no-defaults', '--host=127.0.0.1', '--port=3306', '--user='.$c['username'], '--single-transaction', '--routines', '--events', '--triggers', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF', '--skip-comments', $c['database']], null, ['MYSQL_PWD' => $c['password']]);
    $process->setTimeout(180)->disableOutput();
    try {
        $exit = $process->run(function ($type, $chunk) use ($out) {
            if ($type === Process::OUT && fwrite($out, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Backup write failed.');
            }
        });
    } finally {
        fclose($out);
    }
    if ($exit !== 0 || filesize($name) < 1000) {
        throw new RuntimeException('Backup did not complete; migration not allowed.');
    }
    $seen = [];
    $tail = '';
    $in = fopen($name, 'rb');
    while (($line = fgets($in, 65536)) !== false) {
        if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
            $seen[] = $m[1];
        }$tail = substr($tail.$line, -16384);
    }fclose($in);
    if (array_diff($tables, $seen) || ! str_contains($tail, 'SET SQL_MODE=@OLD_SQL_MODE')) {
        throw new RuntimeException('Backup table inventory/trailer verification failed; migration not allowed.');
    }
    $proof = ['verified_local' => true, 'database' => 'mmtb', 'backup_file' => basename($name), 'backup_bytes' => filesize($name), 'backup_sha256' => hash_file('sha256', $name), 'tables_verified' => count($tables), 'migration' => $migration, 'migration_applied' => false];
    if (file_put_contents($name.'.proof.json', json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Could not persist verified backup proof; migration not allowed.');
    }
    $code = Artisan::call('migrate', ['--path' => 'database/migrations/'.$migration.'.php', '--force' => true]);
    if ($code !== 0 || ! DB::table('migrations')->where('migration', $migration)->exists()) {
        throw new RuntimeException('Migration not verified; preserve backup and inspect LOCAL diagnostics.');
    }
    $proof['migration_applied'] = true;
    file_put_contents($name.'.proof.json', json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo 'LOCAL Phase18.1 migrated after verified SQL backup ('.$proof['backup_bytes'].' bytes). Proof: '.$name.".proof.json\n";
} catch (Throwable $e) {
    // PDO/process exceptions can contain credentials or server text. Never echo them.
    fwrite(STDERR, 'LOCAL preparation stopped. DB/runtime identity, backup or migration could not be verified. No reset/fresh or other migration was requested. Exception class: '.get_class($e)."\n");
    exit(2);
}
