<?php

namespace App\Services\Dossiers\Ai;

use App\Models\DossierAiProposal;
use App\Models\DossierAiRun;
use App\Models\DossierSource;
use App\Models\DossierVersion;
use App\Services\Dossiers\DossierAudit;
use App\Services\Dossiers\DossierService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DossierAiService
{
    public function request(DossierSource $source, int $actor, bool $automatic = false): DossierAiRun
    {
        app(DossierService::class)->check(\Illuminate\Support\Facades\Schema::hasTable('dossier_ai_runs'), 'Cần migration additive Phase18.1 sau khi xác minh và backup LOCAL.');
        app(DossierService::class)->check($source->status === 'ready' && $source->page_count <= 60, 'PDF chưa sẵn sàng hoặc quá60trang cho pilot AI; nhập tay vẫn dùng được.');
        $probe = app(DossierVision::class)->probe();

        return DB::transaction(function () use ($source, $actor, $probe, $automatic) {
            $s = DossierSource::lockForUpdate()->findOrFail($source->id);
            $context = ['machine_id' => $s->machine_id, 'group' => $s->machine?->dossier_group, 'asset_code' => $s->machine?->asset_code, 'chassis_no' => $s->machine?->chassis_no, 'plate' => $s->machine?->plate_no, 'manufacture_year' => $s->machine?->manufacture_year, 'ai_profile' => $probe['ai_profile'] ?? null, 'catalog_sha256' => hash('sha256', DB::table('dossier_types')->whereNotNull('ai_key')->orderBy('ai_key')->pluck('ai_key')->toJson()), 'rule_ids' => DB::table('dossier_ai_rules')->where('enabled', true)->where('effective_from', '<=', app(\App\Services\Dossiers\DossierStatus::class)->now()->toDateString())->pluck('id')->all(), 'provider' => $probe['provider'] ?? null, 'model' => $probe['model'] ?? null];
            $settings = [DB::table('dossier_types')->orderBy('id')->get()->toArray(), DB::table('dossier_ai_rules')->orderBy('id')->get()->toArray()];
            $key = hash('sha256', json_encode([$s->id, $s->sha256, DossierAiSchema::VERSION, $context, $settings]));
            if ($existing = DossierAiRun::where('cache_key', $key)->lockForUpdate()->first()) {
                return $existing;
            }
            $r = DossierAiRun::create(['source_id' => $s->id, 'user_id' => $actor, 'cache_key' => $key, 'pipeline' => DossierAiSchema::VERSION, 'source_revision' => $s->revision, 'context' => $context, 'status' => empty($probe['configured']) ? 'unconfigured' : 'queued']);
            $r->refresh();
            if ($automatic && DossierAiRun::where('id', '!=', $r->id)->count() >= 10) {
                $r->update(['paused' => true, 'error' => 'Đã đạt giới hạn tự xếp10bộ pilot. Người dùng kiểm tra rồi chủ động xếp tiếp từng hồ sơ.']);
            }
            $prior = DossierAiRun::where('source_id', $s->id)->where('pipeline', $r->pipeline)->where('id', '!=', $r->id)->latest('id')->get()->first(fn ($old) => ($old->context['model'] ?? null) === ($context['model'] ?? null) && ($old->context['ai_profile'] ?? null) === $context['ai_profile'] && ($old->context['catalog_sha256'] ?? null) === $context['catalog_sha256']);
            foreach (range(1, $s->page_count) as $p) {
                $cached = $prior?->pages()->where('page', $p)->where('status', 'ready')->first();
                $r->pages()->create($cached ? ['page' => $p, 'dpi' => $cached->dpi, 'status' => 'ready', 'result' => $cached->result, 'image_sha256' => $cached->image_sha256, 'image_object_id' => $cached->image_object_id, 'raw' => ['cached_from_page_id' => $cached->id], 'usage' => [], 'elapsed_ms' => 0] : ['page' => $p]);
            }
            DossierAudit::write('ai_requested', $r, $actor);
            $this->schedule($r);

            return $r;
        });
    }

    public function control(DossierAiRun $run, string $action, ?int $page, int $actor): void
    {
        DB::transaction(function () use ($run, $action, $page, $actor) {
            DB::table('dossier_ai_control')->where('id', 1)->lockForUpdate()->first();
            $r = DossierAiRun::lockForUpdate()->findOrFail($run->id);
            $before = $r->toArray();
            if ($action === 'pause') {
                $r->update(['paused' => true]);
            } else {
                app(DossierService::class)->check(! \App\Models\DossierWorkerJob::whereIn('kind', ['ai_page', 'ai_group'])->where('status', 'processing')->where('lease_until', '>', now())->exists(), 'Có tác vụ AI đang chạy; đợi xong trước đọc lại.');
                // Invalidate queued/expired generations so a late worker cannot publish.
                \App\Models\DossierWorkerJob::whereIn('kind', ['ai_page', 'ai_group'])->whereIn('status', ['pending', 'processing', 'failed'])->get()->filter(fn ($j) => (int) $j->input['run_id'] === $r->id)->each(fn ($j) => $j->update(['status' => 'cancelled', 'claim_token' => null]));
                $pages = $page ? $r->pages()->where('page', $page) : $r->pages()->where('status', 'failed');
                foreach ($pages->lockForUpdate()->get() as $p) {
                    $p->update(['status' => 'pending', 'attempts' => 0, 'dpi' => $page ? 300 : $p->dpi, 'revision' => $p->revision + 1, 'error' => null]);
                }
                $r->update(['paused' => false, 'status' => app(DossierVision::class)->probe()['configured'] ? 'queued' : 'unconfigured', 'group_attempts' => 0, 'error' => null, 'available_at' => null]);
                $this->schedule($r);
            }
            DossierAudit::write('ai_'.$action, $r, $actor, $before);
        }, 3);
    }

    // Legacy queue entries only schedule remote work. No PHP process/PDF execution.
    public function step(int $id): int
    {
        $this->schedule(DossierAiRun::findOrFail($id));

        return 0;
    }

    public function schedule(DossierAiRun $r): void
    {
        if ($r->paused || in_array($r->status, ['ready', 'failed'], true)) {
            return;
        }
        $jobs = app(\App\Services\Dossiers\DossierRemoteJobs::class);
        $schema = app(DossierAiSchema::class);
        $page = $r->pages()->where('status', 'pending')->orderBy('page')->first();
        if ($page) {
            $o = app(\App\Services\Dossiers\DossierObjects::class)->source($r->source, (bool) $r->source->processed_object_id);
            $jobs->enqueue('ai_page', $page->id, ['run_id' => $r->id, 'objects' => ['pdf' => app(\App\Services\Dossiers\DossierObjects::class)->descriptor($o)], 'page' => $page->page, 'dpi' => $page->dpi, 'page_revision' => $page->revision, 'prompt' => $schema->pagePrompt($page->page)], $page->revision);
        } elseif (! $r->pages()->where('status', '!=', 'ready')->exists()) {
            $pages = $r->pages()->orderBy('page')->get()->map(fn ($p) => ['page' => $p->page, 'data' => $p->result])->all();
            $prompt = $schema->groupPrompt($pages, $r->context);
            app(DossierService::class)->check(strlen($prompt) <= 500000, 'Pilot grouping context limit.');
            $jobs->enqueue('ai_group', $r->id, ['run_id' => $r->id, 'objects' => [], 'generation' => $r->generation, 'prompt' => $prompt, 'pages_digest' => \App\Services\Dossiers\DossierRemoteJobs::digest($pages)], $r->generation);
        }
    }

    public function acceptRemote(\App\Models\DossierWorkerJob $job, array $result, $outputs): void
    {
        Validator::make($result, ['response.data' => 'required|array', 'response.usage' => 'present|array', 'elapsed_ms' => 'required|integer|min:0|max:3600000'])->validate();
        // Lock source before run, matching human review lock order.
        $run = DossierAiRun::findOrFail($job->input['run_id']);
        DossierSource::lockForUpdate()->findOrFail($run->source_id);
        $run = DossierAiRun::lockForUpdate()->findOrFail($run->id);
        app(DossierService::class)->check($run->status !== 'ready', 'AI run paused/completed.');
        $schema = app(DossierAiSchema::class);
        $response = $result['response'];
        // Only structured content and usage are retained; no provider headers/credentials.
        $response = ['data' => $response['data'], 'usage' => $response['usage'], 'mock' => (bool) ($result['mock'] ?? false), 'worker_id' => $job->worker_id, 'ai_profile' => $job->ai_profile];
        if ($job->kind === 'ai_page') {
            $p = $run->pages()->lockForUpdate()->findOrFail($job->subject_id);
            app(DossierService::class)->check($p->revision === $job->input['page_revision'] && $p->status === 'pending', 'Stale AI page revision.');
            $normalized = $schema->page($response['data']);
            $image = $outputs['image'];
            $duplicate = $run->pages()->where('id', '!=', $p->id)->where('image_sha256', $image->sha256)->value('page');
            if ($duplicate) {
                $normalized['issues'][] = 'Ảnh trùng chính xác trang '.$duplicate.'; chỉ đề xuất, không tự bỏ trang.';
            }
            $p->update(['status' => 'ready', 'result' => $normalized, 'image_object_id' => $image->id, 'image_sha256' => $image->sha256, 'attempts' => $job->attempts, 'error' => null]);
            $run->update(['status' => 'queued', 'error' => null]);
        } else {
            app(DossierService::class)->check($run->generation === $job->input['generation'] && ! $run->pages()->where('status', '!=', 'ready')->exists(), 'Stale grouping generation.');
            $normalized = $schema->documents($response['data'], $run->source->page_count, $run->context);
            $multi = $run->pages()->get()->filter(fn ($p) => $p->result['multiple_documents'] ?? false)->pluck('page')->all();
            foreach ($normalized as $i => $item) {
                if (array_intersect($multi, $item['data']['pages'])) {
                    $item['data']['multiple_documents'] = true;
                    $item['issues'][] = 'Trang chứa nhiều giấy độc lập; cần xử lý thủ công.';
                }
                $run->proposals()->create(['generation' => $run->generation + 1, 'position' => $i, 'data' => $item['data'], 'issues' => $item['issues']]);
            }
            $run->update(['generation' => $run->generation + 1, 'status' => 'ready', 'group_attempts' => $job->attempts, 'error' => null]);
        }
        $this->schedule($run->fresh());
    }

    public function review(DossierAiProposal $proposal, array $input, int $actor): DossierVersion
    {
        $input = \Illuminate\Support\Arr::only($input, ['source_revision', 'reviewed_evidence', 'acknowledge_stale', 'use_rule', 'rule_scope_confirmed', 'type_id', 'subject', 'subject_id', 'instance', 'pages', 'shared_pages', 'number', 'issuer', 'issued_on', 'certified_on', 'health_signed_on', 'renewal_on', 'effective_at', 'expiry_precision', 'expiry_value', 'month_rule', 'reason', 'note', 'confirmed']);

        return DB::transaction(function () use ($proposal, $input, $actor) {
            $source = DossierSource::lockForUpdate()->findOrFail($proposal->run->source_id);
            $p = DossierAiProposal::lockForUpdate()->findOrFail($proposal->id);
            $check = app(DossierService::class);
            if ($p->version_id) {
                return DossierVersion::findOrFail($p->version_id);
            }
            $check->check($proposal->run->status === 'ready', 'AI chưa hoàn thành.');
            $check->check((int) ($input['source_revision'] ?? -1) === $source->revision, 'Hồ sơ vừa thay đổi; tải lại trước khi duyệt.');
            $check->check($source->revision === $proposal->run->source_revision || ! empty($input['acknowledge_stale']), 'Kết quả AI cũ hơn sửa tay; đối chiếu lại và xác nhận đã kiểm tra.');
            $check->check(empty($p->data['multiple_documents']), 'Trang nhiều giấy độc lập cần xử lý thủ công, chưa hỗ trợ crop xuất.');
            $check->check(! empty($input['reviewed_evidence']), 'Cần xác nhận đã đối chiếu bằng chứng scan, chủ thể và phạm vi.');
            $vdata = $input;
            $vdata['request_id'] = (string) Str::uuid();
            $vdata['source_id'] = $source->id;
            $vdata['shared_pages'] = (bool) ($input['shared_pages'] ?? false);
            $vdata['confirmed'] = (bool) ($input['confirmed'] ?? false);
            $expiryOrigin = 'user_reviewed';
            $rule = null;
            if (! empty($input['use_rule'])) {
                $rule = $p->data['expiry_suggestion'] ?? null;
                $check->check($rule && ! empty($input['rule_scope_confirmed']) && empty($p->data['fields']['expiry']), 'Quy tắc cần đủ căn cứ/phạm vi xác nhận; không ghi đè hạn trên giấy.');
                $check->check((int) $input['type_id'] === (int) $p->data['type_id'], 'Đổi loại giấy: không dùng quy tắc cũ.');
                $check->check(($input[$rule['anchor']] ?? null) === $rule['anchor_value'], 'Ngày gốc đã sửa: nhập hạn được kiểm tra hoặc phân tích lại, không dùng hạn tính từ ngày cũ.');
                $vdata['expiry_value'] = $rule['value'];
                $vdata['expiry_precision'] = $rule['precision'];
                $expiryOrigin = 'rule_reviewed';
            } elseif (isset($p->data['fields']['expiry']['value']) && $p->data['fields']['expiry']['value'] === ($vdata['expiry_value'] ?? null)) {
                $expiryOrigin = 'document_reviewed';
            }
            Validator::make($input, ['health_signed_on' => 'nullable|date_format:Y-m-d', 'renewal_on' => 'nullable|date_format:Y-m-d'])->validate();
            $version = $check->saveVersion($vdata, $actor);
            $version->update(['health_signed_on' => $input['health_signed_on'] ?? null, 'renewal_on' => $input['renewal_on'] ?? null, 'provenance' => ['proposal_id' => $p->id, 'pipeline' => $proposal->run->pipeline, 'evidence' => $p->data['fields'], 'scope_text' => $p->data['scope_text'], 'insured' => $p->data['insured'], 'subject_claim' => $p->data['subject'], 'expiry_origin' => $expiryOrigin, 'rule' => $rule, 'reviewed_by' => $actor, 'reviewed_at' => now()->toIso8601String(), 'reason' => $input['reason']]]);
            $p->update(['reviewed' => $input, 'reviewed_by' => $actor, 'version_id' => $version->id]);
            DossierAudit::write('ai_reviewed', $p, $actor);

            return $version;
        }, 3);
    }
}
