<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DossierWorker;
use App\Models\DossierWorkerJob;
use App\Services\Dossiers\DossierObjects;
use App\Services\Dossiers\DossierRemoteJobs;
use Illuminate\Http\Request;

class DossierWorkerController extends Controller
{
    private function claimHeaders(Request $r): array
    {
        $data = validator(['worker' => $r->header('X-Dossier-Worker'), 'token' => $r->header('X-Dossier-Claim'), 'attempt' => $r->header('X-Dossier-Attempt')], ['worker' => 'required|string|max:100', 'token' => 'required|uuid', 'attempt' => 'required|integer|min:1'])->validate();

        return [$data['worker'], $data['token'], (int) $data['attempt']];
    }

    public function heartbeat(Request $r, DossierRemoteJobs $jobs)
    {
        $w = $jobs->heartbeat($r->all());

        return response()->json(['worker_id' => $w->id, 'lease_seconds' => config('dossiers.worker.lease_seconds'), 'pipeline' => DossierRemoteJobs::PIPELINE]);
    }

    public function claim(Request $r, DossierRemoteJobs $jobs)
    {
        $d = $r->validate(['worker_id' => 'required|string|max:100']);
        $w = DossierWorker::where('last_seen_at', '>=', now()->subSeconds(120))->findOrFail($d['worker_id']);
        $job = $jobs->claim($w);
        if (! $job) {
            return response()->json(['job' => null]);
        }

        return response()->json(['job' => ['id' => $job->id, 'kind' => $job->kind, 'attempt' => $job->attempts, 'claim_token' => $job->claim_token, 'lease_seconds' => config('dossiers.worker.lease_seconds'), 'pipeline' => $job->pipeline, 'input_hash' => $job->input_hash, 'input' => $job->input]]);
    }

    public function renew(Request $r, DossierWorkerJob $job, DossierRemoteJobs $jobs)
    {
        $j = $jobs->renew($job, $this->claimHeaders($r), $r->all());

        return response()->json(['lease_until' => $j->lease_until]);
    }

    public function input(Request $r, DossierWorkerJob $job, string $object, DossierRemoteJobs $jobs, DossierObjects $objects)
    {
        return $objects->response($jobs->input($job, $object, $this->claimHeaders($r)), 'input');
    }

    public function upload(Request $r, DossierWorkerJob $job, string $slot, DossierRemoteJobs $jobs)
    {
        $r->validate(['file' => 'required|file', 'sha256' => 'required|string']);
        $o = $jobs->upload($job, $slot, $r->file('file'), $r->input('sha256'), $this->claimHeaders($r));

        return response()->json(['id' => $o->id, 'sha256' => $o->sha256, 'bytes' => $o->bytes]);
    }

    public function complete(Request $r, DossierWorkerJob $job, DossierRemoteJobs $jobs)
    {
        $j = $jobs->complete($job, $this->claimHeaders($r), $r->all());

        return response()->json(['id' => $j->id, 'status' => $j->status]);
    }

    public function fail(Request $r, DossierWorkerJob $job, DossierRemoteJobs $jobs)
    {
        $d = $r->validate(['code' => 'required|string', 'retryable' => 'required|boolean']);
        $j = $jobs->fail($job, $this->claimHeaders($r), $d['code'], $d['retryable']);

        return response()->json(['status' => $j->status]);
    }
}
