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
    public function request(DossierSource $source, int $actor, bool $automatic = false, bool $manual = false): DossierAiRun
    {
        app(DossierService::class)->check(\Illuminate\Support\Facades\Schema::hasTable('dossier_ai_runs'), 'Cần migration additive Phase18.1 sau khi xác minh và backup LOCAL.');
        app(DossierService::class)->check($source->status === 'ready' && ! $source->driver_reset_retired_at && $source->page_count <= 60, 'PDF chưa sẵn sàng hoặc quá60trang cho pilot AI; nhập tay vẫn dùng được.');
        $probe = app(DossierVision::class)->probe();

        return DB::transaction(function () use ($source, $actor, $probe, $automatic, $manual) {
            $s = DossierSource::lockForUpdate()->findOrFail($source->id);
            app(DossierService::class)->check(! $s->replacement_cancelled_at, 'Bản thay đã hủy.');
            $context = ['machine_id' => $s->machine_id, 'group' => ($s->document_target['subject'] ?? null) === 'driver' ? 'driver' : $s->machine?->dossier_group, 'asset_code' => $s->machine?->asset_code, 'chassis_no' => $s->machine?->chassis_no, 'plate' => $s->machine?->plate_no, 'manufacture_year' => $s->machine?->manufacture_year, 'ai_profile' => $probe['ai_profile'] ?? null, 'catalog_sha256' => hash('sha256', DB::table('dossier_types')->whereNotNull('ai_key')->orderBy('ai_key')->pluck('ai_key')->toJson()), 'rule_ids' => DB::table('dossier_ai_rules')->where('enabled', true)->where('effective_from', '<=', app(\App\Services\Dossiers\DossierStatus::class)->now()->toDateString())->pluck('id')->all(), 'provider' => $probe['provider'] ?? null, 'model' => $probe['model'] ?? null];
            $settings = [DB::table('dossier_types')->orderBy('id')->get()->toArray(), DB::table('dossier_ai_rules')->orderBy('id')->get()->toArray()];
            $key = hash('sha256', json_encode([$s->id, $s->sha256, DossierAiSchema::VERSION, $context, $settings]));
            if ($existing = DossierAiRun::where('cache_key', $key)->lockForUpdate()->first()) {
                if (! $manual && $probe['configured'] && $existing->status === 'unconfigured' && ! $existing->paused) {
                    $existing->update(['status' => 'queued', 'error' => null]);
                    $this->schedule($existing);
                }

                return $existing;
            }
            // The worker binds the model on its first claim. Keep the run queued
            // before that heartbeat when the source and rules are still the same.
            $unbound = [...$context, 'ai_profile' => null, 'provider' => null, 'model' => null];
            $unboundKey = hash('sha256', json_encode([$s->id, $s->sha256, DossierAiSchema::VERSION, $unbound, $settings]));
            $waiting = DossierAiRun::where('cache_key', $unboundKey)->lockForUpdate()->first();
            if ($waiting && (empty($waiting->context['ai_profile']) || $waiting->context['ai_profile'] === $context['ai_profile'])) {
                if (! $manual && $probe['configured'] && $waiting->status === 'unconfigured' && ! $waiting->paused) {
                    $waiting->update(['status' => 'queued', 'error' => null]);
                    $this->schedule($waiting);
                }

                return $waiting;
            }
            $r = DossierAiRun::create(['source_id' => $s->id, 'user_id' => $actor, 'cache_key' => $key, 'pipeline' => DossierAiSchema::VERSION, 'source_revision' => $s->revision, 'context' => $context, 'status' => $manual || empty($probe['configured']) ? 'unconfigured' : 'queued']);
            $r->refresh();
            if ($automatic && ! $s->batch->auto_analyze && DossierAiRun::where('id', '!=', $r->id)->count() >= 10) {
                $r->update(['paused' => true, 'error' => 'Đã đạt giới hạn tự xếp10bộ pilot. Người dùng kiểm tra rồi chủ động xếp tiếp từng hồ sơ.']);
            }
            $prior = DossierAiRun::where('source_id', $s->id)->where('pipeline', $r->pipeline)->where('id', '!=', $r->id)->latest('id')->get()->first(fn ($old) => ($old->context['model'] ?? null) === ($context['model'] ?? null) && ($old->context['ai_profile'] ?? null) === $context['ai_profile'] && ($old->context['catalog_sha256'] ?? null) === $context['catalog_sha256']);
            foreach (range(1, $s->page_count) as $p) {
                $cached = $prior?->pages()->where('page', $p)->where('status', 'ready')->first();
                $r->pages()->create($cached ? ['page' => $p, 'dpi' => $cached->dpi, 'status' => 'ready', 'result' => $cached->result, 'image_sha256' => $cached->image_sha256, 'image_object_id' => $cached->image_object_id, 'raw' => ['cached_from_page_id' => $cached->id], 'usage' => [], 'elapsed_ms' => 0] : ['page' => $p]);
            }
            $previous = DossierAiRun::where('source_id', $s->id)->where('id', '!=', $r->id)->latest('id')->first();
            if ($previous) {
                foreach ($previous->proposals()->where('generation', $previous->generation)->orderBy('position')->get() as $proposal) {
                    if (! $proposal->version_id && empty($proposal->data['classification_edited'])) {
                        continue;
                    }
                    $data = $proposal->data;
                    unset($data['information_pages']);
                    $r->proposals()->create(['generation' => $r->generation, 'position' => $proposal->position,
                        'data' => $data, 'issues' => $proposal->issues, 'version_id' => $proposal->version_id, 'reviewed' => $proposal->reviewed]);
                }
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
                \App\Models\DossierWorkerJob::whereIn('kind', ['ai_page', 'ai_group'])->whereIn('status', ['pending', 'processing', 'failed'])->get()->filter(fn ($j) => ! isset($j->input['proposal_id']) && (int) $j->input['run_id'] === $r->id)->each(fn ($j) => $j->update(['status' => 'cancelled', 'claim_token' => null]));
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
        if ($r->paused || in_array($r->status, ['ready', 'failed', 'unconfigured'], true)) {
            return;
        }
        $jobs = app(\App\Services\Dossiers\DossierRemoteJobs::class);
        $schema = app(DossierAiSchema::class);
        $page = $r->pages()->where('status', 'pending')->orderBy('page')->first();
        if ($page) {
            $existing = \App\Models\DossierWorkerJob::where('kind', 'ai_page')->where('subject_id', (string) $page->id)
                ->whereIn('status', ['pending', 'processing'])->get()
                ->contains(fn ($j) => ! isset($j->input['proposal_id']) && $j->input['page_revision'] === $page->revision);
            if ($existing) {
                return;
            }
            $o = app(\App\Services\Dossiers\DossierObjects::class)->source($r->source, (bool) $r->source->processed_object_id);
            $jobs->enqueue('ai_page', $page->id, ['run_id' => $r->id, 'objects' => ['pdf' => app(\App\Services\Dossiers\DossierObjects::class)->descriptor($o)], 'page' => $page->page, 'dpi' => $page->dpi, 'evidence_views' => true, 'page_revision' => $page->revision, 'prompt' => $schema->pagePrompt($page->page, $r->source->replacementVersion?->record?->type?->ai_key)], $page->revision);
        } elseif (! $r->pages()->where('status', '!=', 'ready')->exists()) {
            $catalog = DossierType::whereNotNull('ai_key')->pluck('ai_key')->flip();
            $pages = $r->pages()->orderBy('page')->get()->filter(fn ($page) => ($page->result['kind'] ?? null) === 'document')
                ->map(fn ($page) => ['page' => $page->page, 'multiple_documents' => $page->result['multiple_documents'],
                    'fragments' => collect($page->result['fragments'])->filter(fn ($fragment) => is_array($fragment) &&
                        is_string($fragment['type_key'] ?? null) && $catalog->has($fragment['type_key']))
                        ->map(fn ($fragment) => ['type_key' => $fragment['type_key'],
                            'continuation' => ($fragment['continuation'] ?? false) === true,
                            'role' => in_array($fragment['role'] ?? null, ['start', 'continuation', 'terms', 'appendix', 'signature'], true) ? $fragment['role'] : 'unknown',
                            'printed_page' => $this->printedPage($fragment['printed_page'] ?? null),
                            'printed_total' => $this->printedPage($fragment['printed_total'] ?? null),
                            'reference_key' => $this->referenceKey($fragment)])->values()->all()])->values()->all();
            if (! $pages) {
                $r->update(['status' => 'ready']);

                return;
            }
            $prompt = $schema->groupPrompt($pages);
            app(DossierService::class)->check(strlen($prompt) <= 500000, 'Pilot grouping context limit.');
            $jobs->enqueue('ai_group', $r->id, ['run_id' => $r->id, 'objects' => [], 'generation' => $r->generation, 'prompt' => $prompt, 'pages_digest' => \App\Services\Dossiers\DossierRemoteJobs::digest($pages)], $r->generation);
        }
    }

    private function printedPage(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= 300 ? $value : null;
    }

    private function referenceKey(array $fragment): ?string
    {
        $reference = $fragment['reference'] ?? null;
        if ($fragment['type_key'] === 'identity' || ! is_string($reference) || strlen($reference) > 80) {
            return null;
        }
        $reference = preg_replace('/[^A-Z0-9]/', '', strtoupper($reference));

        return strlen($reference) >= 3 ? substr(hash_hmac('sha256', $reference, config('app.key')), 0, 24) : null;
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
            $run->proposals()->where('generation', $run->generation)->update(['generation' => $run->generation + 1]);
            $run->update(['status' => $run->pages()->where('status', 'failed')->exists() ? 'failed' : 'queued',
                'generation' => $run->generation + 1, 'group_result' => null]);
            DossierAudit::write('ai_page_label_changed', $page, $actor, ['kind' => $previous['before_label_override']['kind'] ?? null]);
            $this->schedule($run);
        }, 3);
    }

    public function acceptRemote(\App\Models\DossierWorkerJob $job, array $result, $outputs): void
    {
        Validator::make($result, ['response.data' => $job->kind === 'ai_group' ? 'present|array' : 'required|array', 'response.usage' => 'present|array', 'elapsed_ms' => 'required|integer|min:0|max:3600000'])->validate();
        // Lock source before run, matching human review lock order.
        $run = DossierAiRun::findOrFail($job->input['run_id']);
        $source = DossierSource::lockForUpdate()->findOrFail($run->source_id);
        app(DossierService::class)->check(! $source->replacement_cancelled_at, 'Bản thay đã hủy.');
        $run = DossierAiRun::lockForUpdate()->findOrFail($run->id);
        app(DossierService::class)->check((isset($job->input['proposal_id']) || $run->status !== 'ready'), 'AI run paused/completed.');
        $schema = app(DossierAiSchema::class);
        $response = $result['response'];
        // Only structured content and usage are retained; no provider headers/credentials.
        $response = ['data' => $response['data'], 'usage' => $response['usage'], 'mock' => (bool) ($result['mock'] ?? false), 'worker_id' => $job->worker_id, 'ai_profile' => $job->ai_profile];
        if (isset($job->input['proposal_id'])) {
            $this->acceptInformation($run, $job, $response['data']);

            return;
        }
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
            $eligible = $run->pages->filter(fn ($page) => ($page->result['kind'] ?? null) === 'document' && empty($page->result['multiple_documents']))->pluck('page')->all();
            $items = $response['data']['documents'] ?? [];
            $next = $run->generation + 1;
            $preserved = $run->proposals()->where('generation', $run->generation)->orderBy('position')->get();
            $reserved = $preserved->flatMap(fn ($proposal) => $proposal->data['pages'])->all();
            foreach ($preserved as $proposal) {
                $proposal->update(['generation' => $next]);
            }
            $position = 1 + (int) $preserved->max('position');
            $candidates = [];
            $counts = [];
            foreach (array_slice(is_array($items) ? $items : [], 0, 100) as $item) {
                $pages = is_array($item) ? ($item['pages'] ?? null) : null;
                $key = is_array($item) ? ($item['type_key'] ?? null) : null;
                if (! is_array($pages) || ! $pages || array_filter($pages, fn ($p) => ! is_int($p) || ! in_array($p, $eligible, true))) {
                    continue;
                }
                $pages = array_values(array_unique($pages));
                foreach ($pages as $page) {
                    $counts[$page] = ($counts[$page] ?? 0) + 1;
                }
                if (! is_string($key) || ! empty($item['error']) || ! empty($item['multiple_documents'])) {
                    continue;
                }
                $type = DossierType::where('ai_key', $key)->first();
                if ($type) {
                    $candidates[] = [$type, $pages];
                }
            }
            foreach ($candidates as [$type, $pages]) {
                if (array_intersect($pages, $reserved) || array_filter($pages, fn ($page) => $counts[$page] !== 1)) {
                    continue;
                }
                $run->proposals()->create(['generation' => $next, 'position' => $position++,
                    'data' => $schema->classification($type, $pages, $run->context), 'issues' => []]);
            }
            $run->update(['generation' => $next, 'status' => 'ready', 'group_attempts' => $job->attempts, 'error' => null]);
        }
        $this->schedule($run->fresh());
    }

    public function classify(DossierAiRun $run, DossierType $type, array $pages, int $actor, ?DossierAiProposal $proposal = null): void
    {
        DB::transaction(function () use ($run, $type, $pages, $actor, $proposal) {
            $source = DossierSource::lockForUpdate()->findOrFail($run->source_id);
            $run = DossierAiRun::lockForUpdate()->findOrFail($run->id);
            app(DossierService::class)->check($source->status === 'ready' && ! $source->driver_reset_retired_at && ! $source->replacement_cancelled_at &&
                $pages && min($pages) >= 1 && max($pages) <= $source->page_count, 'Trang hoặc hồ sơ không hợp lệ.');
            $current = $run->proposals()->where('generation', $run->generation);
            $other = (clone $current)->when($proposal, fn ($q) => $q->where('id', '!=', $proposal->id))->get();
            app(DossierService::class)->check(! $other->contains(fn ($p) => array_intersect($pages, $p->data['pages'])), 'Trang đã thuộc một giấy khác.');
            $data = app(DossierAiSchema::class)->classification($type, $pages, $run->context);
            $data['classification_edited'] = true;
            if ($proposal) {
                $proposal = $current->whereNull('version_id')->findOrFail($proposal->id);
                app(DossierService::class)->check($data['pages'] === $proposal->data['pages'], 'Đổi loại không đổi phạm vi trang.');
                $data['information_revision'] = ($proposal->data['information_revision'] ?? 0) + 1;
                $before = $this->classificationSnapshot($proposal);
                $proposal->update(['data' => $data, 'issues' => []]);
            } else {
                $proposal = $current->create(['generation' => $run->generation, 'position' => 1 + (int) $current->max('position'), 'data' => $data, 'issues' => []]);
            }
            DossierAudit::write('ai_classification_saved', $proposal, $actor, ['classification' => $before ?? null]);
        }, 3);
    }

    public function editPages(DossierAiProposal $proposal, array $pages, bool $append, bool $transfer, int $actor): void
    {
        DB::transaction(function () use ($proposal, $pages, $append, $transfer, $actor) {
            $source = DossierSource::lockForUpdate()->findOrFail($proposal->run->source_id);
            $run = DossierAiRun::lockForUpdate()->findOrFail($proposal->run_id);
            $check = app(DossierService::class);
            $current = $run->proposals()->where('generation', $run->generation);
            $target = (clone $current)->whereNull('version_id')->findOrFail($proposal->id);
            $check->check($source->status === 'ready' && ! $source->driver_reset_retired_at && ! $source->replacement_cancelled_at &&
                $pages && min($pages) >= 1 && max($pages) <= $source->page_count, 'Trang hoặc hồ sơ không hợp lệ.');
            $pages = $append ? array_values(array_unique([...$target->data['pages'], ...$pages])) : $pages;
            foreach ((clone $current)->where('id', '!=', $target->id)->get() as $other) {
                $remaining = array_values(array_diff($other->data['pages'], $pages));
                if ($remaining === $other->data['pages']) {
                    continue;
                }
                $check->check($transfer && ! $other->version_id, 'Trang đã thuộc giấy khác hoặc đã xác nhận.');
                $check->check((bool) $remaining, 'Không thể chuyển hết trang khỏi giấy nguồn. Giữ lại ít nhất một trang.');
                $this->saveEditedPages($other, $remaining, $actor);
            }
            $this->saveEditedPages($target, $pages, $actor);
        }, 3);
    }

    private function classificationSnapshot(DossierAiProposal $proposal): array
    {
        return ['type_id' => $proposal->data['type_id'], 'pages' => $proposal->data['pages']];
    }

    private function saveEditedPages(DossierAiProposal $proposal, array $pages, int $actor): void
    {
        if ($pages === $proposal->data['pages']) {
            return;
        }
        $before = $this->classificationSnapshot($proposal);
        $data = app(DossierAiSchema::class)->classification(DossierType::findOrFail($before['type_id']), $pages, $proposal->run->context);
        $data['classification_edited'] = true;
        $data['information_revision'] = ($proposal->data['information_revision'] ?? 0) + 1;
        $proposal->update(['data' => $data, 'issues' => []]);
        DossierAudit::write('ai_classification_pages_changed', $proposal, $actor, ['classification' => $before]);
    }

    public function requestInformation(DossierAiProposal $proposal): void
    {
        DB::transaction(function () use ($proposal) {
            $source = DossierSource::lockForUpdate()->findOrFail($proposal->run->source_id);
            $run = DossierAiRun::lockForUpdate()->findOrFail($proposal->run_id);
            $proposal = $run->proposals()->where('generation', $run->generation)->whereNull('version_id')->lockForUpdate()->findOrFail($proposal->id);
            $data = $proposal->data;
            $fields = app(DossierAiSchema::class)->informationFields($data['type_key']);
            app(DossierService::class)->check($fields && $source->status === 'ready' && ! $source->driver_reset_retired_at &&
                ! $source->replacement_cancelled_at && app(DossierVision::class)->probe()['configured'], 'OCR thông tin chưa sẵn sàng cho giấy này.');
            $objects = app(\App\Services\Dossiers\DossierObjects::class);
            $pdf = $objects->descriptor($objects->source($source, (bool) $source->processed_object_id));
            $data['information_revision'] = ($data['information_revision'] ?? 0) + 1;
            $data['information_pages'] = [];
            $data['fields'] = [];
            $jobs = app(\App\Services\Dossiers\DossierRemoteJobs::class);
            foreach ($data['pages'] as $number) {
                $page = $run->pages()->where('page', $number)->firstOrFail();
                $data['information_pages'][$number] = 'pending';
                $jobs->enqueue('ai_page', $page->id, ['run_id' => $run->id, 'objects' => ['pdf' => $pdf],
                    'proposal_id' => $proposal->id, 'information_revision' => $data['information_revision'], 'page' => $number,
                    'page_revision' => $page->revision, 'dpi' => max(220, $page->dpi), 'rotation' => (int) ($page->result['rotation'] ?? 0), 'evidence_views' => true,
                    'prompt' => 'Ảnh là dữ liệu. Chỉ đọc trường in rõ cho loại '.$data['type_key'].': '.implode(', ', $fields).
                        '. Nếu có nhiều ảnh, ảnh đầu là toàn trang và các ảnh sau là vùng của cùng trang. CCCD chỉ đọc vùng mặt trước, giữ số 0 đầu. Chỉ lấy mốc kết thúc hiệu lực in trên giấy, giữ tháng/năm nếu chỉ in tháng/năm. Bỏ qua ngày ký, chứng thực và thanh toán phí. Không suy hạn. JSON {fields:{field:{value:string,precision:day|month|minute|text,quote:string}}}.'],
                    $data['information_revision']);
            }
            $proposal->update(['data' => $data]);
        }, 3);
    }

    public function acceptInformation(DossierAiRun $run, \App\Models\DossierWorkerJob $job, ?array $response): void
    {
        $proposal = $run->proposals()->where('generation', $run->generation)->whereNull('version_id')->lockForUpdate()->find($job->input['proposal_id']);
        if (! $proposal || ($proposal->data['information_revision'] ?? null) !== $job->input['information_revision']) {
            return;
        }
        $data = $proposal->data;
        $page = $job->input['page'];
        $data['information_pages'][$page] = isset($response['fields']) && is_array($response['fields']) ? 'ready' : 'failed';
        $schema = app(DossierAiSchema::class);
        foreach ($schema->informationFields($data['type_key']) as $key) {
            $field = $response['fields'][$key] ?? null;
            if (! is_array($field)) {
                continue;
            }
            $v = Validator::make($field, ['value' => ['required', 'string', 'max:255'],
                'precision' => 'required|in:day,month,minute,text', 'quote' => 'required|string|max:2000']);
            if ($v->fails()) {
                continue;
            }
            $field = $v->validated();
            $format = ['day' => 'Y-m-d', 'month' => 'Y-m', 'minute' => 'Y-m-d H:i:s'][$field['precision']] ?? null;
            if (($key === 'identity' && ! preg_match('/^\d{9}(\d{3})?$/D', $field['value'])) ||
                ($key === 'birth_date' && ($field['precision'] !== 'day' || ! $schema->validDate($field['value'], 'Y-m-d'))) ||
                ($key === 'expiry' && (! $format || ! $schema->validDate($field['value'], $format)))) {
                continue;
            }
            $data['fields'][$key] ??= [...$field, 'page' => $page, 'box' => null];
        }
        $proposal->update(['data' => $data]);
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
