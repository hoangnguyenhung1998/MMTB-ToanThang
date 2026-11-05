<?php

namespace Tests\Support;

use App\Models\DossierExport;
use App\Models\DossierSource;
use App\Models\DossierWorker;
use App\Models\DossierWorkerJob;
use App\Services\Dossiers\DossierObjects;
use App\Services\Dossiers\DossierRemoteJobs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class DossierTestWorker
{
    public static function worker(): DossierWorker
    {
        return app(DossierRemoteJobs::class)->heartbeat(['worker_id' => 'local-test', 'capabilities' => ['prepare', 'export', 'ai_page', 'ai_group'], 'versions' => ['test' => '1'], 'ai_ready' => true, 'ai_profile' => str_repeat('a', 64), 'provider' => 'mock-only', 'model' => 'mock']);
    }

    public static function claim(DossierWorkerJob $job): array
    {
        // Business tests isolate a selected job; protocol/concurrency tests use claim() directly.
        $job->update(['status' => 'processing', 'worker_id' => 'local-test', 'claim_token' => (string) \Illuminate\Support\Str::uuid(), 'attempts' => $job->attempts + 1, 'claimed_at' => now(), 'lease_until' => now()->addMinutes(3)]);

        return ['local-test', $job->claim_token, $job->attempts];
    }

    public static function run(int|string $id, string $kind): void
    {
        $subject = ($kind === 'prepare' ? DossierSource::class : DossierExport::class)::findOrFail($id);
        if ($subject->status === 'ready') {
            return;
        }
        $jobs = app(DossierRemoteJobs::class);
        $job = $jobs->$kind($subject);
        $claim = self::claim($job);
        $work = sys_get_temp_dir().'/dossier-test-'.\Illuminate\Support\Str::uuid();
        mkdir($work, 0700, true);
        $store = app(DossierObjects::class);
        $paths = [];
        try {

            foreach ($job->input['objects'] as $o) {
                $object = \App\Models\DossierObject::findOrFail($o['id']);
                $path = $work.'/'.$object->id.'.pdf';
                $in = Storage::disk($object->disk)->readStream($object->key);
                $out = fopen($path, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $paths[$object->id] = $path;
            }
            $poppler = getenv('DOSSIERS_PDFTOPPM') ?: 'C:/Users/Administrator/.cache/codex-runtimes/codex-primary-runtime/dependencies/native/poppler/Library/bin/pdftoppm.exe';
            $p = new Process([getenv('DOSSIERS_TEST_PYTHON') ?: base_path('dossier-worker/.venv/Scripts/python.exe'), base_path('tests/Support/dossier_pdf_bridge.py')]);
            $p->setTimeout(180);
            $p->setInput(json_encode(['kind' => $kind, 'work' => $work, 'source' => reset($paths), 'input' => $job->input, 'paths' => $paths, 'binaries' => [getenv('DOSSIERS_QPDF') ?: 'C:/Program Files/PDF24/qpdf/bin/qpdf.exe', getenv('DOSSIERS_PDFINFO') ?: dirname($poppler).'/pdfinfo'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), $poppler]]));
            $p->mustRun();
            $data = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['outputs'] as $slot => $path) {
                $jobs->upload($job, $slot, new UploadedFile($path, basename($path), null, null, true), hash_file('sha256', $path), $claim);
            }
            $jobs->complete($job, $claim, ['input_hash' => $job->input_hash, 'pipeline' => $job->pipeline, 'result' => $data['result']]);
        } finally {
            foreach (glob($work.'/*') as $f) {
                unlink($f);
            }rmdir($work);
        }
    }
}
