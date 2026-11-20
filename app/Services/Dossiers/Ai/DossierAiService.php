<?php

namespace App\Services\Dossiers\Ai;

use App\Models\DossierAiProposal;
use App\Models\DossierAiRun;
use App\Models\DossierSource;
use App\Models\DossierType;
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
        app(DossierService::class)->check($source->status === 'ready' && ! $source->driver_reset_retired_at && $source->page_count <= 60, 'PDF chưa sẵn sàng hoặc quá60trang cho pilot AI; nhập tay vẫn dùng được.');
        if ($source->machine_id && ! $source->replacement_cancelled_at) {
            app(\App\Services\DossierZaloRoster::class)->provisionForMachine($source->machine_id, $actor);
        }
        $probe = app(DossierVision::class)->probe();

        return DB::transaction(function () use ($source, $actor, $probe, $automatic) {
            $s = DossierSource::lockForUpdate()->findOrFail($source->id);
            app(DossierService::class)->check(! $s->replacement_cancelled_at, 'Bản thay đã hủy.');
            $context = ['machine_id' => $s->machine_id, 'group' => ($s->document_target['subject'] ?? null) === 'driver' ? 'driver' : $s->machine?->dossier_group, 'asset_code' => $s->machine?->asset_code, 'chassis_no' => $s->machine?->chassis_no, 'plate' => $s->machine?->plate_no, 'manufacture_year' => $s->machine?->manufacture_year, 'ai_profile' => $probe['ai_profile'] ?? null, 'catalog_sha256' => hash('sha256', DB::table('dossier_types')->whereNotNull('ai_key')->orderBy('ai_key')->pluck('ai_key')->toJson()), 'rule_ids' => DB::table('dossier_ai_rules')->where('enabled', true)->where('effective_from', '<=', app(\App\Services\Dossiers\DossierStatus::class)->now()->toDateString())->pluck('id')->all(), 'provider' => $probe['provider'] ?? null, 'model' => $probe['model'] ?? null];
            $settings = [DB::table('dossier_types')->orderBy('id')->get()->toArray(), DB::table('dossier_ai_rules')->orderBy('id')->get()->toArray()];
            $key = hash('sha256', json_encode([$s->id, $s->sha256, DossierAiSchema::VERSION, $context, $settings]));
            if ($existing = DossierAiRun::where('cache_key', $key)->lockForUpdate()->first()) {
                return $existing;
            }
            // The worker binds the model on its first claim. Keep the run queued
            // before that heartbeat when the source and rules are still the same.
            $unbound = [...$context, 'ai_profile' => null, 'provider' => null, 'model' => null];
            $unboundKey = hash('sha256', json_encode([$s->id, $s->sha256, DossierAiSchema::VERSION, $unbound, $settings]));
            $waiting = DossierAiRun::where('cache_key', $unboundKey)->lockForUpdate()->first();
            if ($waiting && (empty($waiting->context['ai_profile']) || $waiting->context['ai_profile'] === $context['ai_profile'])) {
                if ($probe['configured'] && $waiting->status === 'unconfigured' && ! $waiting->paused) {
                    $waiting->update(['status' => 'queued', 'error' => null]);
                    $this->schedule($waiting);
                }

                return $waiting;
            }
            $r = DossierAiRun::create(['source_id' => $s->id, 'user_id' => $actor, 'cache_key' => $key, 'pipeline' => DossierAiSchema::VERSION, 'source_revision' => $s->revision, 'context' => $context, 'status' => empty($probe['configured']) ? 'unconfigured' : 'queued']);
            $r->refresh();
            if ($automatic && ! $s->batch->auto_analyze && DossierAiRun::where('id', '!=', $r->id)->count() >= 10) {
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
            $existing = \App\Models\DossierWorkerJob::where('kind', 'ai_page')->where('subject_id', (string) $page->id)
                ->whereIn('status', ['pending', 'processing'])->get()
                ->contains(fn ($j) => $j->input['page_revision'] === $page->revision);
            if ($existing) {
                return;
            }
            $o = app(\App\Services\Dossiers\DossierObjects::class)->source($r->source, (bool) $r->source->processed_object_id);
            $jobs->enqueue('ai_page', $page->id, ['run_id' => $r->id, 'objects' => ['pdf' => app(\App\Services\Dossiers\DossierObjects::class)->descriptor($o)], 'page' => $page->page, 'dpi' => $page->dpi, 'page_revision' => $page->revision, 'prompt' => $schema->pagePrompt($page->page, $r->source->replacementVersion?->record?->type?->ai_key)], $page->revision);
        } elseif (! $r->pages()->where('status', '!=', 'ready')->exists()) {
            $pages = $r->pages()->orderBy('page')->get()->reject(fn ($p) => ($p->result['kind'] ?? null) === 'blank')->map(fn ($p) => ['page' => $p->page, 'data' => $p->result])->values()->all();
            if (! $pages) {
                $r->update(['status' => 'ready', 'generation' => $r->generation + 1]);

                return;
            }
            $prompt = $schema->groupPrompt($pages, $r->context);
            app(DossierService::class)->check(strlen($prompt) <= 500000, 'Pilot grouping context limit.');
            $jobs->enqueue('ai_group', $r->id, ['run_id' => $r->id, 'objects' => [], 'generation' => $r->generation, 'prompt' => $prompt, 'pages_digest' => \App\Services\Dossiers\DossierRemoteJobs::digest($pages)], $r->generation);
        }
    }

    public function label(DossierAiRun $run, int $number, string $kind, int $actor): void
    {
        DB::transaction(function () use ($run, $number, $kind, $actor) {
            DB::table('dossier_ai_control')->where('id', 1)->lockForUpdate()->firstOrFail();
            $run = DossierAiRun::lockForUpdate()->findOrFail($run->id);
            $page = $run->pages()->where('page', $number)->lockForUpdate()->firstOrFail();
            $check = app(DossierService::class);
            $check->check($page->status === 'ready', 'Chỉ đổi nhãn trang đã đọc.');
            $jobs = \App\Models\DossierWorkerJob::where('kind', 'ai_group')->where('subject_id', (string) $run->id)
                ->whereIn('status', ['pending', 'processing'])->lockForUpdate()->get();
            $check->check(! $jobs->contains(fn ($job) => $job->status === 'processing' && $job->lease_until?->gt(now())), 'Đợi nhóm giấy đang chạy hoàn tất.');
            if ($page->result['kind'] === $kind) {
                return;
            }
            $previous = $page->raw ?? [];
            $page->update(['raw' => [...$previous, 'before_label_override' => $previous['before_label_override'] ?? $page->result],
                'result' => [...$page->result, 'kind' => $kind]]);
            $jobs->each(fn ($job) => $job->update(['status' => 'cancelled', 'claim_token' => null]));
            $run->update(['status' => $run->pages()->where('status', 'failed')->exists() ? 'failed' : 'queued',
                'generation' => $run->generation + 1, 'group_result' => null]);
            DossierAudit::write('ai_page_label_changed', $page, $actor, ['kind' => $previous['before_label_override']['kind'] ?? null]);
            $this->schedule($run);
        }, 3);
    }

    public function acceptRemote(\App\Models\DossierWorkerJob $job, array $result, $outputs): void
    {
        Validator::make($result, ['response.data' => 'required|array', 'response.usage' => 'present|array', 'elapsed_ms' => 'required|integer|min:0|max:3600000'])->validate();
        // Lock source before run, matching human review lock order.
        $run = DossierAiRun::findOrFail($job->input['run_id']);
        $source = DossierSource::lockForUpdate()->findOrFail($run->source_id);
        app(DossierService::class)->check(! $source->replacement_cancelled_at, 'Bản thay đã hủy.');
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
            $blank = $run->pages()->get()->filter(fn ($p) => ($p->result['kind'] ?? null) === 'blank')->pluck('page')->all();
            foreach ($normalized as $item) {
                app(DossierService::class)->check(! array_intersect($blank, $item['data']['pages']), 'AI nhóm giấy chứa trang trắng.');
            }
            $unreadable = $run->pages()->get()->filter(fn ($p) => ($p->result['kind'] ?? null) === 'unknown')->pluck('page')->all();
            $multi = $run->pages()->get()->filter(fn ($p) => $p->result['multiple_documents'] ?? false)->pluck('page')->all();
            foreach ($normalized as $i => $item) {
                if (array_intersect($unreadable, $item['data']['pages'])) {
                    $item['issues'][] = 'Có trang mờ hoặc chưa đọc được; cần kiểm tra riêng.';
                }
                if (array_intersect($multi, $item['data']['pages'])) {
                    $item['data']['multiple_documents'] = true;
                    $item['issues'][] = 'Trang chứa nhiều giấy độc lập; cần xử lý thủ công.';
                }
                $run->proposals()->create(['generation' => $run->generation + 1, 'position' => $i, 'data' => $item['data'], 'issues' => $item['issues']]);
            }
            $run->update(['generation' => $run->generation + 1, 'status' => 'ready', 'group_attempts' => $job->attempts, 'error' => null]);
            if ($run->pipeline === DossierAiSchema::VERSION) {
                $this->autoClassify($run, $blank);
            }
        }
        $this->schedule($run->fresh());
    }

    private function autoClassify(DossierAiRun $run, array $blank): void
    {
        $source = $run->source->fresh();
        if (! $source->machine_confirmed || $source->resolution === 'REPLACEMENT_MISMATCH' || $source->replacement_cancelled_at || $source->revision !== $run->source_revision) {
            return;
        }
        $target = $source->replacementVersion;
        $candidates = app(\App\Services\DossierZaloRoster::class)->applicants($source);
        $compare = app(DossierComparison::class);
        $proposals = $run->proposals()->where('generation', $run->generation)->get();
        foreach ($proposals as $proposal) {
            $data = $proposal->data;
            $type = $data['type_key'];
            $issues = array_filter($proposal->issues, fn ($issue) => ! str_starts_with($issue, 'Phải chọn/xác nhận') &&
                ! str_starts_with($issue, 'Tên trùng chỉ') && ! str_starts_with($issue, 'Suy chu kỳ đăng kiểm'));
            if (! $data['type_id'] || $data['multiple_documents'] || $issues) {
                continue;
            }
            $record = $target?->record;
            $subject = $data['subject'];
            $id = $subject === 'machine' ? $source->machine_id : ($record?->driver_id ?? $compare->suggestedDriver($data, $source, $candidates));
            $comparison = $compare->compare($data, $source, $subject, $id);
            $identity = [];
            if ($type === 'identity') {
                $fields = $data['fields'];
                if ($subject !== 'driver' || $comparison['status'] !== 'match' || empty($fields['person_name']['value']) || empty($fields['identity']['value']) || empty($fields['birth_date']['value'])) {
                    continue;
                }
                $identity = ['verify_identity' => true, 'person_name' => $fields['person_name']['value'],
                    'identity' => $fields['identity']['value'], 'birth_date' => $fields['birth_date']['value']];
            }
            $tracking = in_array($type, DossierAiSchema::EXPIRY_TYPES, true);
            $expiry = $data['fields']['expiry'] ?? null;
            if ($target) {
                $expected = array_values(array_diff(range(1, $source->page_count), $blank));
                if ($proposals->count() !== 1 || $data['pages'] !== $expected || $record->type_id !== $data['type_id'] ||
                    ($subject === 'driver' ? ! $record->driver_id : $subject !== 'machine' || $record->machine_id !== $id) ||
                    $comparison['status'] === 'mismatch' || ($tracking && $comparison['status'] !== 'match')) {
                    continue;
                }
            } else {
                $sameType = $proposals->filter(fn ($candidate) => $candidate->data['type_id'] === $data['type_id'] && $candidate->data['subject'] === $subject && ($subject !== 'driver' || $compare->suggestedDriver($candidate->data, $source, $candidates) === $id));
                if ($sameType->count() !== 1 || ! $id || $comparison['status'] !== 'match') {
                    continue;
                }
            }
            if ($tracking && ($expiry ? ! in_array($expiry['precision'], ['day', 'minute'], true) : ! $data['permanent_explicit'])) {
                continue;
            }
            $pages = $run->pages()->whereIn('page', $data['pages'])->get()->keyBy('page');
            try {
                $this->review($proposal, [
                    ...$identity,
                    'source_revision' => $source->revision, 'acknowledge_stale' => true,
                    'reviewed_evidence' => true, 'type_id' => $data['type_id'], 'subject' => $subject, 'subject_id' => $id,
                    'record_id' => $record?->id, 'instance' => $record?->instance ?? 'main',
                    'pages' => array_map(fn ($page) => ['page' => $page, 'rotation' => $pages[$page]->result['rotation'] ?? 0], $data['pages']),
                    'effective_at' => app(\App\Services\Dossiers\DossierStatus::class)->now()->format('Y-m-d H:i:s'),
                    'expiry_precision' => ! $tracking ? 'not_tracked' : ($expiry['precision'] ?? 'permanent'),
                    'expiry_value' => $expiry['value'] ?? null, 'confirmed' => true, 'reason' => 'Tự phân loại theo hồ sơ và bằng chứng OCR.',
                ], $run->user_id, automatic: true);
                $source->refresh();
                $run->update(['source_revision' => $source->revision]);
            } catch (\Illuminate\Validation\ValidationException) {
                // The unchanged proposal remains available for manual review.
            }
        }
    }

    public function review(DossierAiProposal $proposal, array $input, int $actor, bool $automatic = false): DossierVersion
    {
        $input = \Illuminate\Support\Arr::only($input, ['source_revision', 'reviewed_evidence', 'type_checked', 'bic_page', 'bic_quote', 'verify_identity', 'person_name', 'birth_date', 'identity', 'acknowledge_stale', 'use_rule', 'rule_scope_confirmed', 'type_id', 'subject', 'subject_id', 'record_id', 'instance', 'pages', 'shared_pages', 'number', 'issuer', 'issued_on', 'certified_on', 'health_signed_on', 'renewal_on', 'effective_at', 'expiry_precision', 'expiry_value', 'month_rule', 'reason', 'note', 'confirmed']);

        return DB::transaction(function () use ($proposal, $input, $actor, $automatic) {
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
            $check->check(! $source->replacement_cancelled_at, 'Bản thay đã hủy.');
            $check->check($p->generation === $p->run->generation, 'Đề xuất đã được thay bởi lần nhóm mới.');
            $type = DossierType::findOrFail($input['type_id']);
            $tracking = in_array($type->ai_key, DossierAiSchema::EXPIRY_TYPES, true);
            if ($tracking && (int) $input['type_id'] !== $p->data['type_id'] && ($input['expiry_precision'] ?? 'unknown') !== 'unknown') {
                $check->check(! empty($input['type_checked']), 'Xác nhận đúng loại giấy trước khi lưu hạn.');
            }
            $check->check(! $tracking || ($input['expiry_precision'] ?? '') !== 'not_tracked', 'Loại giấy này cần trạng thái hạn.');
            $verifiedIdentity = null;
            $insurance = null;
            if (! empty($input['confirmed']) && $p->data['type_key'] === 'bic_personal' && empty($input['bic_quote'])) {
                $comparison = app(DossierComparison::class)->compare($p->data, $source, $input['subject'], (int) $input['subject_id']);
                $check->check($comparison['status'] === 'match' && DossierType::findOrFail($input['type_id'])->ai_key === 'bic_personal',
                    'BIC cần dòng danh sách có bằng chứng định danh người được bảo hiểm.');
                $matching = app(DossierComparison::class);
                $insurance = $matching->insuranceEvidence($p->data, $matching->identity((int) $input['subject_id']));
            }
            if (! empty($input['verify_identity']) && ! empty($input['confirmed'])) {
                $check->check($input['subject'] === 'driver' && $p->data['type_key'] === 'identity' &&
                    DossierType::findOrFail($input['type_id'])->ai_key === 'identity', 'Chỉ xác lập định danh từ CCCD đã đối chiếu.');
                $verifiedIdentity = app(DossierComparison::class)->verifyIdentity($input);
            }
            $vdata = $input;
            if (! $tracking) {
                $vdata['expiry_precision'] = 'not_tracked';
            }
            if ($source->replacement_version_id) {
                $processed = $p->run->pages()->where('status', 'ready')->get()->reject(fn ($page) => ($page->result['kind'] ?? null) === 'blank')->pluck('page')->sort()->values()->all();
                $selected = array_map('intval', array_column($input['pages'], 'page'));
                sort($selected);
                $check->check($selected === $processed && $processed !== [], 'Bản thay phải có đủ mọi trang giấy, theo đối chiếu trang nguồn.');
            }
            $vdata['request_id'] = (string) Str::uuid();
            $vdata['source_id'] = $source->id;
            $vdata['shared_pages'] = (bool) ($input['shared_pages'] ?? false);
            $vdata['confirmed'] = (bool) ($input['confirmed'] ?? false);
            $expiryOrigin = 'user_reviewed';
            $rule = null;
            if (! empty($input['use_rule'])) {
                $rule = $p->data['expiry_suggestion'] ?? null;
                $check->check($tracking && $rule && ! empty($input['rule_scope_confirmed']) && empty($p->data['fields']['expiry']), 'Quy tắc cần đủ căn cứ/phạm vi xác nhận; không ghi đè hạn trên giấy.');
                $check->check((int) $input['type_id'] === (int) $p->data['type_id'], 'Đổi loại giấy: không dùng quy tắc cũ.');
                $check->check(($input[$rule['anchor']] ?? null) === $rule['anchor_value'], 'Ngày gốc đã sửa: nhập hạn được kiểm tra hoặc phân tích lại, không dùng hạn tính từ ngày cũ.');
                $vdata['expiry_value'] = $rule['value'];
                $vdata['expiry_precision'] = $rule['precision'];
                $expiryOrigin = 'rule_reviewed';
            } elseif (isset($p->data['fields']['expiry']['value']) && $p->data['fields']['expiry']['value'] === ($vdata['expiry_value'] ?? null)) {
                $expiryOrigin = 'document_reviewed';
            }
            Validator::make($input, ['health_signed_on' => 'nullable|date_format:Y-m-d', 'renewal_on' => 'nullable|date_format:Y-m-d'])->validate();
            $version = $check->saveVersion($vdata, $actor, insuranceEvidence: $insurance,
                newScopeOnly: $automatic && ! $source->replacement_version_id);
            $version->update(['health_signed_on' => $input['health_signed_on'] ?? null, 'renewal_on' => $input['renewal_on'] ?? null, 'provenance' => ['verified_identity' => $verifiedIdentity, 'insurance_evidence' => $insurance ?? ($version->provenance['insurance_evidence'] ?? null), 'proposal_id' => $p->id, 'pipeline' => $proposal->run->pipeline, 'evidence' => $p->data['fields'], 'scope_text' => $p->data['scope_text'], 'insured' => $p->data['insured'], 'subject_claim' => $p->data['subject'], 'expiry_origin' => $expiryOrigin, 'rule' => $rule, 'reviewed_by' => $actor, 'reviewed_at' => now()->toIso8601String(), 'reason' => $input['reason']]]);
            $p->update(['reviewed' => $input, 'reviewed_by' => $actor, 'version_id' => $version->id]);
            DossierAudit::write($automatic ? 'ai_auto_classified' : 'ai_reviewed', $p, $actor);

            return $version;
        }, 3);
    }
}
