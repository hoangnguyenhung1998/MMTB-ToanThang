<?php

// Shares guarded isolated-MySQL bootstrap; no production/application DB access.
require __DIR__.'/DossierConcurrency.php';
use App\Models\DossierObject;
use App\Models\DossierSource;
use App\Models\DossierWorkerJob;
use App\Services\Dossiers\DossierObjects;
use App\Services\Dossiers\DossierRemoteJobs;

$jobs = app(DossierRemoteJobs::class);
$stateFile = $root.'/remote-state.json';
if ($mode === 'remote-setup') {
    DossierWorkerJob::whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'lease_until' => null]);
    $src = DossierSource::findOrFail($s['rounds']['baseline']['source_id']);
    $src->update(['status' => 'queued']);
    $object = app(DossierObjects::class)->source($src);
    $job = $jobs->enqueue('prepare', $src->id, ['objects' => ['source' => app(DossierObjects::class)->descriptor($object)], 'max_pages' => config('dossiers.max_pages')], random_int(1, PHP_INT_MAX));
    foreach (['leader', 'follower'] as $who) {
        $jobs->heartbeat(['worker_id' => $who, 'capabilities' => ['prepare'], 'versions' => ['test' => 'native-concurrency'], 'ai_ready' => false]);
    }
    file_put_contents($stateFile, json_encode(['job_id' => $job->id]));
    echo json_encode(['remote_setup' => true]);
    exit;
}
$rstate = json_decode(file_get_contents($stateFile), true);
if ($mode === 'remote-complete-setup') {
    $job = DossierWorkerJob::findOrFail($rstate['job_id']);
    $claim = [$job->worker_id, $job->claim_token, $job->attempts];
    $src = DossierSource::findOrFail($job->subject_id);
    $obj = app(DossierObjects::class)->source($src);
    $pdf = Illuminate\Http\UploadedFile::fake()->createWithContent('processed.pdf', Storage::disk($obj->disk)->get($obj->key));
    $image = Illuminate\Http\UploadedFile::fake()->image('preview.png', 20, 20);
    foreach (['processed' => $pdf, 'thumbnail-1' => $image] as $slot => $f) {
        $jobs->upload($job, $slot, $f, hash_file('sha256', $f->getRealPath()), $claim);
    }
    $rstate['claim'] = $claim;
    $rstate['payload'] = ['input_hash' => $job->input_hash, 'pipeline' => $job->pipeline, 'result' => ['page_count' => 1, 'geometry_before' => [['size:1', 'rot:0', 'MediaBox:1', 'CropBox:1', 'BleedBox:1', 'TrimBox:1', 'ArtBox:1']], 'geometry_after' => [['size:1', 'rot:0', 'MediaBox:1', 'CropBox:1', 'BleedBox:1', 'TrimBox:1', 'ArtBox:1']], 'normalize_exit' => 0, 'warnings' => [], 'qpdf_version' => '12.3.2-test-attestation', 'validation' => ['qpdf_check_exit' => 0, 'reopened_pages' => 1, 'trial_export_pages' => 1, 'rasterized' => false]]];
    file_put_contents($stateFile, json_encode($rstate));
    echo json_encode(['outputs' => 2]);
    exit;
}
if ($mode === 'remote-reclaim-setup') {
    $old = DossierWorkerJob::findOrFail($rstate['job_id']);
    $job = $jobs->enqueue('prepare', $old->subject_id, $old->input, 1);
    $src = DossierSource::findOrFail($old->subject_id);
    $src->update(['status' => 'queued']);
    $w = App\Models\DossierWorker::findOrFail('leader');
    $job = $jobs->claim($w);
    $rstate['job_id'] = $job->id;
    $rstate['claim'] = [$job->worker_id, $job->claim_token, $job->attempts];
    $rstate['payload']['input_hash'] = $job->input_hash;
    $job->update(['lease_until' => now()->subSecond()]);
    file_put_contents($stateFile, json_encode($rstate));
    echo json_encode(['expired_attempt' => 1]);
    exit;
}
if ($mode === 'remote-worker') {
    $gate = $root.'/remote-'.$round.getenv('P18_PROOF_SUFFIX');
    $conn = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    file_put_contents($gate.'-'.$role.'.ready', 'ready');
    waitFile($gate.'.go');
    if ($role === 'follower') {
        waitFile($gate.'.holding');
    }$start = microtime(true);
    $out = ['pid' => getmypid(), 'connection_id' => $conn, 'round' => $round, 'role' => $role];
    if ($role === 'leader') {
        DB::beginTransaction();
        if ($round === 'complete') {
            DossierWorkerJob::whereKey($rstate['job_id'])->lockForUpdate()->firstOrFail();
        } else {
            DB::table('dossier_ai_control')->where('id', 1)->lockForUpdate()->first();
        }
    }
    try {
        if ($round === 'claim' || ($round === 'reclaim' && $role === 'leader')) {
            $j = $jobs->claim(App\Models\DossierWorker::findOrFail($role));
            $out['claimed'] = $j?->id;
            $out['attempt'] = $j?->attempts;
        } else {
            $j = $jobs->complete(DossierWorkerJob::findOrFail($rstate['job_id']), $rstate['claim'], $rstate['payload']);
            $out['status'] = $j->status;
        }
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $out['http'] = $e->getStatusCode();
    }
    if ($role === 'leader') {
        file_put_contents($gate.'.holding', 'held');
        usleep(1500000);
        DB::commit();
    }
    $out['elapsed_ms'] = round((microtime(true) - $start) * 1000, 2);
    echo json_encode($out);
    exit;
}
if ($mode === 'remote-verify') {
    $j = DossierWorkerJob::findOrFail($rstate['job_id']);
    if ($round === 'claim') {
        check($j->status === 'processing' && $j->attempts === 1, 'One valid claimant required');
    } elseif ($round === 'complete') {
        check($j->status === 'succeeded', 'Completion did not publish');
        check(DossierObject::where('job_id', $j->id)->where('status', 'published')->count() === 2, 'Outputs duplicated/lost');
    } else {
        check($j->attempts === 2 && $j->worker_id === 'leader', 'Reclaim did not fence prior worker');
        $j->update(['status' => 'cancelled', 'lease_until' => null]);
    }
    check(App\Models\DossierVersion::whereHas('record', fn ($q) => $q->where('machine_id', $s['machine_id']))->count() === 7, 'Version history changed by remote transport');
    echo json_encode(['verified' => $round, 'attempts' => $j->attempts, 'versions_retained' => 7, 'objects' => DossierObject::where('job_id', $j->id)->count()]);
    exit;
}
