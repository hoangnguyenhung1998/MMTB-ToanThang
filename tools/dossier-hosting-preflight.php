<?php

// CLI-only, sanitized, read-only hosting inventory. Never calls an AI endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function dossierProbe(array $arguments): array
{
    if (! function_exists('proc_open')) {
        return ['ok' => false, 'reason' => 'proc_open unavailable'];
    }
    try {
        $p = new Process($arguments);
        $p->setTimeout(20)->run();

        return ['ok' => $p->isSuccessful(), 'exit' => $p->getExitCode(), 'version_line' => preg_replace('/[\r\n].*/s', '', substr(trim($p->getOutput() ?: $p->getErrorOutput()), 0, 300))];
    } catch (Throwable $e) {
        return ['ok' => false, 'exception' => get_class($e)];
    }
}
$opts = getopt('', ['python:', 'qpdf:', 'pdfinfo:', 'pdftoppm:']);
$result = ['php_cli' => PHP_VERSION, 'sapi' => PHP_SAPI, 'environment' => app()->environment(), 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'), 'cli_upload_max_filesize' => ini_get('upload_max_filesize'), 'cli_post_max_size' => ini_get('post_max_size'), 'process' => ['proc_open' => function_exists('proc_open'), 'proc_terminate' => function_exists('proc_terminate'), 'pcntl' => extension_loaded('pcntl')], 'extensions' => array_map(fn ($e) => extension_loaded($e), array_combine(['pdo_mysql', 'fileinfo', 'mbstring', 'zip', 'gd', 'zlib', 'dom'], ['pdo_mysql', 'fileinfo', 'mbstring', 'zip', 'gd', 'zlib', 'dom']))];
foreach (['qpdf' => '--version', 'pdfinfo' => '-v', 'pdftoppm' => '-v'] as $tool => $option) {
    $result['tools'][$tool] = dossierProbe([$opts[$tool] ?? config('dossiers.'.$tool, $tool), $option]);
}
preg_match('/qpdf version ([0-9.]+)/', $result['tools']['qpdf']['version_line'] ?? '', $qpdfVersion);
$result['qpdf_warning_recovery_qualified'] = isset($qpdfVersion[1]) && version_compare($qpdfVersion[1], '12.3.2', '>=');
$python = $opts['python'] ?? config('dossiers.ai.python', 'python3');
$result['python'] = dossierProbe([$python, '-c', 'import sys,httpx,pydantic; print("Python "+sys.version.split()[0]+"; httpx="+httpx.__version__+"; pydantic="+pydantic.__version__); sys.exit(0 if sys.version_info >= (3,12) else 2)']);
$root = config('filesystems.disks.dossiers.root', storage_path('app/dossiers-private'));
$existing = is_dir($root) ? realpath($root) : realpath(dirname($root));
$public = realpath(public_path());
$result['storage'] = ['disk_driver' => config('filesystems.disks.dossiers.driver', 'not_configured'), 'private_root_exists' => is_dir($root), 'parent_writable' => $existing && is_writable($existing), 'outside_public' => $existing && $public && ! str_starts_with($existing.DIRECTORY_SEPARATOR, $public.DIRECTORY_SEPARATOR), 'public_serving' => config('filesystems.disks.dossiers.serve', false), 'free_bytes' => $existing ? disk_free_space($existing) : null];
$expected = ['2026_10_28_000001_create_electronic_dossiers', '2026_10_29_000001_add_dossier_pdf_processing', '2026_10_30_000001_create_dossier_ai'];
try {
    $ran = DB::table('migrations')->pluck('migration')->all();
    $result['missing_dossier_migrations'] = array_values(array_diff($expected, $ran));
    $all = array_map(fn ($p) => basename($p, '.php'), glob(base_path('database/migrations/*.php')));
    $result['other_pending_migrations'] = array_values(array_diff($all, $ran, $expected));
} catch (Throwable $e) {
    $result['database_inventory'] = ['verified' => false, 'exception' => get_class($e)];
}
$result['safety'] = ['ai_enabled' => (bool) config('dossiers.ai.enabled', false), 'automatic_analysis' => (bool) config('dossiers.ai.auto_analyze', false), 'ai_endpoint_configured' => (bool) config('dossiers.ai.endpoint'), 'ai_key_configured' => (bool) config('dossiers.ai.key'), 'ai_model_configured' => (bool) config('dossiers.ai.model'), 'telegram_enabled' => (bool) config('dossiers.telegram_enabled', false), 'recipient_count' => count(config('dossiers.telegram_recipients', []))];
$result['not_verified'] = ['PHP web/FPM limits may differ from CLI', 'external process RAM/wall-time limits', 'actual hosting cron execution and locks', 'backup/restore integrity', 'authorized real user Gate access', 'endpoint DNS/TLS/egress after user config; NO API REQUEST MADE'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
