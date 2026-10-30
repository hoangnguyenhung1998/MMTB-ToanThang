<?php

require __DIR__.'/DossierRemoteHttpBootstrap.php';
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    $u = App\Models\User::factory()->create(['email' => 'dossier-e2e@example.test', 'password' => Hash::make($test['password'])]);
    $m = App\Models\Machine::create(['asset_code' => 'T-XX0709', 'company' => 'VINCONS', 'chassis_no' => 'E2E-ONLY-0709', 'status' => 'ACTIVE', 'dossier_group' => 'machine']);
    $test['user_id'] = $u->id;
    $test['machine_id'] = $m->id;
    $test['type_id'] = App\Models\DossierType::where('ai_key', 'machine_inspection')->value('id');
    file_put_contents($private, json_encode($test));
    echo json_encode(['setup' => true, 'machine' => $m->asset_code]);
} elseif ($mode === 'enqueue-crash-export') {
    $machine = App\Models\Machine::findOrFail($test['machine_id']);
    $source = App\Models\DossierSource::where('original_name', 'T-XX0709.pdf')->firstOrFail();
    $v = App\Models\DossierVersion::where('source_id', $source->id)->where('confirmed', true)->latest('effective_at')->firstOrFail();
    $e = app(App\Services\Dossiers\DossierExportService::class)->create($machine, [], [$v->id], (string) Illuminate\Support\Str::uuid(), true, $test['user_id']);
    echo json_encode(['export_id' => $e->id]);
} elseif ($mode === 'verify-crash') {
    $e = App\Models\DossierExport::where('machine_id', $test['machine_id'])->latest('created_at')->firstOrFail();
    $job = App\Models\DossierWorkerJob::where('kind', 'export')->where('subject_id', $e->id)->firstOrFail();
    if ($e->status !== 'ready' || $job->status !== 'succeeded' || $job->attempts !== 1 || App\Models\DossierObject::where('job_id', $job->id)->count() !== 1) {
        throw new RuntimeException('Crash retry duplicated or lost output');
    }
    app(App\Services\Dossiers\DossierObjects::class)->verify(App\Models\DossierObject::findOrFail($e->object_id));
    $proof = ['controlled_exit' => 97, 'restarted_process_complete' => true, 'job_attempts' => $job->attempts, 'output_receipts' => 1, 'ready' => true, 'outbox_clean' => ! glob($root.'/crash-worker-state/job-*')];
    file_put_contents($root.'/crash-recovery-proof.json', json_encode($proof));
    echo json_encode($proof);
} elseif ($mode === 'clear-spacing') {
    DB::table('dossier_ai_control')->update(['next_call_at' => null]);
    echo '{}';
} elseif ($mode === 'state') {
    $src = App\Models\DossierSource::where('original_name', 'T-XX0709.pdf')->first();
    $run = $src ? App\Models\DossierAiRun::where('source_id', $src->id)->latest('id')->first() : null;
    echo json_encode(['source' => $src?->toArray(), 'run' => $run?->toArray(), 'pages' => $run?->pages()->get()->toArray(), 'proposals' => $run?->proposals()->get()->toArray(), 'versions' => $src ? App\Models\DossierVersion::where('source_id', $src->id)->get()->toArray() : [], 'exports' => App\Models\DossierExport::where('machine_id', $test['machine_id'])->get()->toArray()]);
}
