<?php

// LOCAL isolated E2E router only. Never expose this test server externally.
if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
require __DIR__.'/DossierRemoteHttpBootstrap.php';
$disk = Storage::disk('dossiers');
Storage::set('dossiers', new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends Illuminate\Filesystem\FilesystemAdapter
{
    public function path($path)
    {
        throw new RuntimeException('R2-like adapter has no local path');
    }
});
Illuminate\Support\Facades\Vite::useHotFile($root.'/no-vite-hot-file');
$app->handleRequest(Illuminate\Http\Request::capture());
