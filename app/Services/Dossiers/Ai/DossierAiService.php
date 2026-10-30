<?php

namespace App\Services\Dossiers\Ai;

use App\Jobs\AnalyzeDossier;
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
            $context = ['machine_id' => $s->machine_id, 'group' => $s->machine?->dossier_group, 'asset_code' => $s->machine?->asset_code, 'chassis_no' => $s->machine?->chassis_no, 'plate' => $s->machine?->plate_no, 'manufacture_year' => $s->machine?->manufacture_year, 'endpoint_sha256' => hash('sha256', (string) config('dossiers.ai.endpoint')), 'catalog_sha256' => hash('sha256', DB::table('dossier_types')->whereNotNull('ai_key')->orderBy('ai_key')->pluck('ai_key')->toJson()), 'rule_ids' => DB::table('dossier_ai_rules')->where('enabled', true)->where('effective_from', '<=', app(\App\Services\Dossiers\DossierStatus::class)->now()->toDateString())->pluck('id')->all(), 'provider' => $probe['provider'] ?? null, 'model' => $probe['model'] ?? null];
            $settings = [DB::table('dossier_types')->orderBy('id')->get()->toArray(), DB::table('dossier_ai_rules')->orderBy('id')->get()->toArray()];
            $key = hash('sha256', json_encode([$s->id, $s->sha256, DossierAiSchema::VERSION, $context, $settings]));
            if ($existing = DossierAiRun::where('cache_key', $key)->lockForUpdate()->first()) {
                return $existing;
            }
            $r = DossierAiRun::create(['source_id' => $s->id, 'user_id' => $actor, 'cache_key' => $key, 'pipeline' => DossierAiSchema::VERSION, 'source_revision' => $s->revision, 'context' => $context, 'status' => empty($probe['configured']) ? 'unconfigured' : 'queued']);
            if ($automatic && DossierAiRun::where('id', '!=', $r->id)->count() >= 10) {
                $r->update(['paused' => true, 'error' => 'Đã đạt giới hạn tự xếp10bộ pilot. Người dùng kiểm tra rồi chủ động xếp tiếp từng hồ sơ.']);
            }
            $prior = DossierAiRun::where('source_id', $s->id)->where('pipeline', $r->pipeline)->where('id', '!=', $r->id)->latest('id')->get()->first(fn ($old) => ($old->context['model'] ?? null) === ($context['model'] ?? null) && ($old->context['endpoint_sha256'] ?? null) === $context['endpoint_sha256'] && ($old->context['catalog_sha256'] ?? null) === $context['catalog_sha256']);
            foreach (range(1, $s->page_count) as $p) {
                $cached = $prior?->pages()->where('page', $p)->where('status', 'ready')->first();
                $r->pages()->create($cached ? ['page' => $p, 'dpi' => $cached->dpi, 'status' => 'ready', 'result' => $cached->result, 'image_sha256' => $cached->image_sha256, 'raw' => ['cached_from_page_id' => $cached->id], 'usage' => [], 'elapsed_ms' => 0] : ['page' => $p]);
            }
            DossierAudit::write('ai_requested', $r, $actor);
            if ($r->status === 'queued' && ! $r->paused) {
                AnalyzeDossier::dispatch($r->id)->afterCommit();
            }

            return $r;
        });
    }

    public function control(DossierAiRun $run, string $action, ?int $page, int $actor): void
    {
        DB::transaction(function () use ($run, $action, $page, $actor) {
            $r = DossierAiRun::lockForUpdate()->findOrFail($run->id);
            $before = $r->toArray();
            if ($action === 'pause') {
                $r->update(['paused' => true]);
            } else {
                if ($page) {
                    app(DossierService::class)->check(! DB::table('dossier_ai_control')->whereNotNull('token')->where('lease_until', '>', now())->exists(), 'Có tác vụ AI đang chạy; đợi xong trước đọc lại trang.');
                    $p = $r->pages()->where('page', $page)->lockForUpdate()->firstOrFail();
                    $p->update(['status' => 'pending', 'attempts' => 0, 'dpi' => 300, 'error' => null]);
                } else {
                    $r->pages()->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'error' => null]);
                }
                $r->update(['paused' => false, 'status' => app(DossierVision::class)->probe()['configured'] ? 'queued' : 'unconfigured', 'group_attempts' => 0, 'error' => null, 'available_at' => null]);
                if ($r->status === 'queued') {
                    AnalyzeDossier::dispatch($r->id)->afterCommit();
                }
            }
            DossierAudit::write('ai_'.$action, $r, $actor, $before);
        });
    }

    public function step(int $id): int
    {
        if (! app(DossierVision::class)->probe()['configured']) {
            DossierAiRun::whereKey($id)->whereIn('status', ['queued', 'processing'])->update(['status' => 'unconfigured', 'error' => null]);

            return 0;
        }
        $claim = DB::transaction(function () use ($id) {
            $slot = DB::table('dossier_ai_control')->where('id', 1)->lockForUpdate()->first();
            $r = DossierAiRun::lockForUpdate()->findOrFail($id);
            if ($r->paused || ! in_array($r->status, ['queued', 'processing'], true)) {
                return null;
            }
            if (($slot->lease_until && $slot->lease_until > now()->format('Y-m-d H:i:s')) || ($slot->next_call_at && $slot->next_call_at > now()->format('Y-m-d H:i:s')) || ($r->available_at && $r->available_at > now()->format('Y-m-d H:i:s'))) {
                return ['wait' => 15];
            }
            $token = (string) Str::uuid();
            DB::table('dossier_ai_control')->where('id', 1)->update(['token' => $token, 'lease_until' => now()->addSeconds(420), 'next_call_at' => now()->addSeconds(max(10, (int) config('dossiers.ai.spacing_seconds', 10)))]);
            $r->pages()->where('status', 'pending')->where('attempts', '>=', 3)->update(['status' => 'failed', 'error' => 'Đã đạt3lần thử, kiểm tra trước retry.']);
            $page = $r->pages()->where('status', 'pending')->orderBy('page')->first();
            if (! $page && $r->group_attempts >= 3) {
                $r->update(['status' => 'failed', 'error' => 'Nhóm giấy đã đạt3lần thử.']);
                DB::table('dossier_ai_control')->where('id', 1)->update(['token' => null, 'lease_until' => null]);

                return null;
            }
            if (! $page && $r->pages()->where('status', 'failed')->exists()) {
                $r->update(['status' => 'failed', 'error' => 'Một số trang lỗi; xem và retry riêng.']);
                DB::table('dossier_ai_control')->where('id', 1)->update(['token' => null, 'lease_until' => null]);

                return null;
            }
            if ($page) {
                $page->increment('attempts');
            } else {
                $r->increment('group_attempts');
            }
            $r->update(['status' => 'processing', 'error' => null]);

            return ['run' => $r, 'page' => $page, 'token' => $token];
        });
        if (! $claim) {
            return 0;
        }
        if (isset($claim['wait'])) {
            return $claim['wait'];
        }
        $r = $claim['run'];
        $p = $claim['page'];
        $started = microtime(true);
        $response = null;
        $normalized = null;
        $image = null;
        try {
            $schema = app(DossierAiSchema::class);
            if ($p) {
                $image = app(DossierAiImage::class)->render($r->source, $p->page, $p->dpi);
                $response = app(DossierVision::class)->read($schema->pagePrompt($p->page), $image['path']);
                $normalized = $schema->page($response['data']);
            } else {
                $pages = $r->pages()->orderBy('page')->get()->map(fn ($p) => ['page' => $p->page, 'data' => $p->result])->all();
                $prompt = $schema->groupPrompt($pages, $r->context);
                if (strlen($prompt) > 500000) {
                    throw new \RuntimeException('Pilot grouping context limit.');
                }
                $response = app(DossierVision::class)->read($prompt);
                $normalized = $schema->documents($response['data'], $r->source->page_count, $r->context);
                $multi = $r->pages()->get()->filter(fn ($row) => $row->result['multiple_documents'] ?? false)->pluck('page')->all();
                foreach ($normalized as &$item) {
                    if (array_intersect($multi, $item['data']['pages'])) {
                        $item['data']['multiple_documents'] = true;
                        $item['issues'][] = 'Trang chứa nhiều giấy độc lập; cần xử lý thủ công.';
                    }
                }
                unset($item);
            }
            $error = false;
        } catch (\Throwable) {
            $error = true;
        }

        return DB::transaction(function () use ($r, $p, $claim, $response, $normalized, $image, $started, $error) {
            $slot = DB::table('dossier_ai_control')->where('id', 1)->lockForUpdate()->first();
            if ($slot->token !== $claim['token']) {
                return 0;
            } // expired/crashed claimant cannot publish late
            $run = DossierAiRun::lockForUpdate()->findOrFail($r->id);
            $ms = (int) round((microtime(true) - $started) * 1000);
            if ($response) {
                if ($p) {
                    $p->update(['raw' => ['attempts' => [...($p->raw['attempts'] ?? []), $response]], 'usage' => $this->sumUsage($p->usage ?? [], $response['usage'] ?? []), 'elapsed_ms' => ($p->elapsed_ms ?? 0) + $ms]);
                } else {
                    $run->update(['group_result' => [...$response, 'attempts' => [...($run->group_result['attempts'] ?? []), $response]], 'group_usage' => $this->sumUsage($run->group_usage ?? [], $response['usage'] ?? []), 'group_ms' => ($run->group_ms ?? 0) + $ms]);
                }
            }
            if ($error) {
                $attempts = $p ? $p->attempts : $run->group_attempts;
                if ($p) {
                    $p->update(['status' => $attempts >= 3 ? 'failed' : 'pending', 'error' => 'AI/trình đọc/schema lỗi; chưa có kết quả hợp lệ. Retry tối đa3lần.']);
                }
                $run->update(['status' => ! $p && $attempts >= 3 ? 'failed' : 'queued', 'available_at' => now()->addSeconds($attempts >= 2 ? 120 : 30), 'error' => 'AI chưa hoàn thành; dữ liệu thủ công và hồ sơ đã xác nhận được giữ nguyên.']);
            } elseif ($p) {
                $duplicate = $run->pages()->where('id', '!=', $p->id)->where('image_sha256', $image['sha256'])->value('page');
                if ($duplicate) {
                    $normalized['issues'][] = 'Ảnh trùng chính xác trang '.$duplicate.'; chỉ đề xuất, không tự bỏ trang.';
                }
                $p->update(['status' => 'ready', 'result' => $normalized, 'image_sha256' => $image['sha256'], 'error' => null]);
                $run->update(['status' => 'queued', 'available_at' => null]);
            } else {
                $generation = $run->generation + 1;
                foreach ($normalized as $i => $item) {
                    $run->proposals()->create(['generation' => $generation, 'position' => $i, 'data' => $item['data'], 'issues' => $item['issues']]);
                }
                $run->update(['generation' => $generation, 'status' => 'ready', 'available_at' => null]);
            }
            DB::table('dossier_ai_control')->where('id', 1)->update(['token' => null, 'lease_until' => null, 'next_call_at' => now()->addSeconds(max(10, (int) config('dossiers.ai.spacing_seconds', 10)))]);

            return ! $run->paused && $run->status === 'queued' ? ($error ? ($p && $p->attempts >= 2 ? 120 : 30) : 10) : 0;
        });
    }

    private function sumUsage(array $before, array $next): array
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            if (isset($next[$key])) {
                $before[$key] = (int) ($before[$key] ?? 0) + (int) $next[$key];
            }
        }
        $before['responses_with_usage'] = (int) ($before['responses_with_usage'] ?? 0) + (int) (count($next) > 0);

        return $before;
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
