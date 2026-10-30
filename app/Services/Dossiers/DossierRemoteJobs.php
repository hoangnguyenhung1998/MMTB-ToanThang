<?php

namespace App\Services\Dossiers;

use App\Models\DossierAiPage;
use App\Models\DossierAiRun;
use App\Models\DossierExport;
use App\Models\DossierObject;
use App\Models\DossierSource;
use App\Models\DossierWorker;
use App\Models\DossierWorkerJob;
use App\Services\Dossiers\Ai\DossierAiService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DossierRemoteJobs
{
    public const PIPELINE = 'dossier-remote-v2';

    public static function digest(array $data): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return hash('sha256', json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function enqueue(string $kind, string|int $subject, array $input, int $revision = 0): DossierWorkerJob
    {
        $input = ['pipeline' => self::PIPELINE, ...$input];
        $hash = self::digest($input);
        $key = hash('sha256', "$kind:$subject:$revision:$hash");
        $existing = DossierWorkerJob::where('dedupe_key', $key)->first();
        if ($existing) {
            if ($existing->status === 'cancelled') {
                $existing->update(['status' => 'pending', 'attempts' => 0, 'claim_token' => null, 'worker_id' => null, 'lease_until' => null, 'available_at' => null, 'error_code' => null]);
            }

            return $existing;
        }

        return DossierWorkerJob::createOrFirst(['dedupe_key' => $key], ['id' => (string) Str::uuid(), 'kind' => $kind, 'subject_id' => (string) $subject, 'dedupe_key' => $key, 'pipeline' => self::PIPELINE, 'input' => $input, 'input_hash' => $hash]);
    }

    public function prepare(DossierSource $source): ?DossierWorkerJob
    {
        if ($source->status === 'ready') {
            return null;
        }

        return DB::transaction(function () use ($source) {
            $s = DossierSource::lockForUpdate()->findOrFail($source->id);
            $obj = app(DossierObjects::class)->source($s);
            $job = $this->enqueue('prepare', $s->id, ['objects' => ['source' => app(DossierObjects::class)->descriptor($obj)], 'max_pages' => config('dossiers.max_pages')]);
            if ($job->status === 'failed') {
                $job->update(['status' => 'pending', 'attempts' => 0, 'claim_token' => null, 'worker_id' => null, 'lease_until' => null, 'available_at' => null, 'error_code' => null]);
            }

            return $job;
        });
    }

    public function export(DossierExport $export): ?DossierWorkerJob
    {
        if ($export->status === 'ready') {
            return null;
        }
        $objects = [];
        $manifest = [];
        $store = app(DossierObjects::class);
        foreach ($export->manifest as $item) {
            $source = DossierSource::findOrFail($item['source_id']);
            $a = ! empty($item['source_object_id']) ? DossierObject::where('status', 'published')->findOrFail($item['source_object_id']) : $store->source($source);
            app(DossierService::class)->check(hash_equals($a->sha256, $item['source_sha256']), 'Nguồn không khớp manifest.');
            $objects[$a->id] = $store->descriptor($a);
            $b = null;
            if (! empty($item['processed_sha256'])) {
                $b = DossierObject::where('status', 'published')->where('sha256', $item['processed_sha256'])->whereKey($item['processed_object_id'] ?? $source->processed_object_id)->firstOrFail();
                $objects[$b->id] = $store->descriptor($b);
            }
            $manifest[] = ['version_id' => $item['version_id'], 'source_object' => $a->id, 'processed_object' => $b?->id, 'pages' => $item['pages']];
        }
        app(DossierService::class)->check(collect($manifest)->sum(fn ($m) => count($m['pages'])) <= config('dossiers.max_pages') && collect($objects)->sum('bytes') <= config('dossiers.worker.input_max_bytes', 268435456), 'Bộ xuất quá lớn cho worker pilot (tối đa300trang/256MB input); chọn ít giấy hơn.');
        $job = $this->enqueue('export', $export->id, ['objects' => $objects, 'manifest' => $manifest, 'manifest_hash' => self::digest($export->manifest), 'expected_pages' => collect($manifest)->sum(fn ($m) => count($m['pages']))]);
        if ($job->status === 'failed') {
            $job->update(['status' => 'pending', 'attempts' => 0, 'claim_token' => null, 'worker_id' => null, 'lease_until' => null, 'available_at' => null, 'error_code' => null]);
        }

        return $job;
    }

    public function heartbeat(array $data): DossierWorker
    {
        $data = Validator::make($data, ['worker_id' => 'required|regex:/^[A-Za-z0-9_.-]{1,100}$/', 'capabilities' => 'required|array', 'capabilities.*' => 'in:prepare,export,ai_page,ai_group', 'versions' => 'required|array', 'versions.*' => 'string|max:200', 'ai_ready' => 'required|boolean', 'ai_profile' => 'nullable|regex:/^[a-f0-9]{64}$/', 'provider' => 'nullable|string|max:100', 'model' => 'nullable|string|max:200'])->validate();
        if ($data['ai_ready']) {
            Validator::make($data, ['ai_profile' => 'required', 'provider' => 'required', 'model' => 'required'])->validate();
        }
        $id = $data['worker_id'];
        unset($data['worker_id']);

        return DossierWorker::updateOrCreate(['id' => $id], [...$data, 'last_seen_at' => now()]);
    }

    public function claim(DossierWorker $worker): ?DossierWorkerJob
    {
        return DB::transaction(function () use ($worker) {
            $control = DB::table('dossier_ai_control')->where('id', 1)->lockForUpdate()->first();
            abort_unless($control, 503, 'Dossier coordination not initialized.');
            if (DossierWorkerJob::where('status', 'processing')->where('lease_until', '>', now())->exists()) {
                return null;
            }
            $kinds = array_intersect(['prepare', 'export', 'ai_page', 'ai_group'], $worker->capabilities);
            if (! $worker->ai_ready || ! config('dossiers.ai.enabled')) {
                $kinds = array_diff($kinds, ['ai_page', 'ai_group']);
            }
            $jobs = DossierWorkerJob::whereIn('kind', $kinds)->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'processing')->where('lease_until', '<=', now())))->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->oldest('created_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($jobs as $j) {
                if ($j->attempts >= config('dossiers.worker.max_attempts', 3)) {
                    $j->update(['status' => 'failed', 'error_code' => 'attempts_exhausted']);
                    $this->domainFailure($j);

                    continue;
                }
                if (str_starts_with($j->kind, 'ai_')) {
                    $r = DossierAiRun::findOrFail($j->input['run_id']);
                    if ($r->paused) {
                        continue;
                    }
                    $slot = DB::table('dossier_ai_control')->where('id', 1)->first();
                    if ($slot->next_call_at && $slot->next_call_at > now()->format('Y-m-d H:i:s')) {
                        continue;
                    }
                    if (($r->context['ai_profile'] ?? null) && $r->context['ai_profile'] !== $worker->ai_profile) {
                        $r->update(['status' => 'unconfigured', 'error' => 'Cấu hình AI worker đã đổi. Bấm Phân tích AI để tạo đề xuất riêng.']);

                        continue;
                    }
                    $r->update(['context' => [...$r->context, 'ai_profile' => $worker->ai_profile, 'provider' => $worker->provider, 'model' => $worker->model], 'status' => 'processing']);
                }
                $j->update(['status' => 'processing', 'worker_id' => $worker->id, 'claim_token' => (string) Str::uuid(), 'attempts' => $j->attempts + 1, 'claimed_at' => now(), 'lease_until' => now()->addSeconds(config('dossiers.worker.lease_seconds', 180)), 'ai_profile' => str_starts_with($j->kind, 'ai_') ? $worker->ai_profile : null, 'error_code' => null]);

                return $j->fresh();
            }

            return null;
        }, 3);
    }

    public function owner(DossierWorkerJob $job, string $worker, string $token, int $attempt, bool $completed = false): void
    {
        abort_unless($job->worker_id === $worker && hash_equals((string) $job->claim_token, $token) && $job->attempts === $attempt, 409, 'Claim no longer owns this job.');
        if ($completed && $job->status === 'succeeded') {
            return;
        }
        abort_unless($job->status === 'processing' && $job->lease_until && $job->lease_until->gt(now()), 409, 'Lease expired.');
    }

    public function renew(DossierWorkerJob $job, array $claim, array $progress = []): DossierWorkerJob
    {
        return DB::transaction(function () use ($job, $claim, $progress) {
            $j = DossierWorkerJob::lockForUpdate()->findOrFail($job->id);
            $this->owner($j, ...$claim);
            abort_if($j->claimed_at->addSeconds(config('dossiers.worker.max_lease_lifetime', 3600))->lt(now()), 409, 'Maximum claim lifetime.');
            $data = Validator::make($progress, ['progress' => 'sometimes|integer|min:0|max:300', 'stage' => 'sometimes|in:download,normalize,thumbnail,ai,merge,upload,complete'])->validate();
            $j->update([...$data, 'lease_until' => now()->addSeconds(config('dossiers.worker.lease_seconds', 180))]);
            DossierWorker::whereKey($j->worker_id)->update(['last_seen_at' => now()]);

            return $j;
        });
    }

    public function input(DossierWorkerJob $job, string $objectId, array $claim): DossierObject
    {
        $this->owner($job->fresh(), ...$claim);
        $ids = array_column($job->input['objects'] ?? [], 'id');
        abort_unless(in_array($objectId, $ids, true), 404);
        $o = DossierObject::where('status', 'published')->findOrFail($objectId);
        $expected = collect($job->input['objects'])->firstWhere('id', $objectId);
        abort_unless(hash_equals($expected['sha256'], $o->sha256) && $expected['bytes'] === $o->bytes, 409);

        return $o;
    }

    public function upload(DossierWorkerJob $job, string $slot, UploadedFile $file, string $expectedSha, array $claim): DossierObject
    {
        $j = $job->fresh();
        $this->owner($j, ...[...$claim, true]);
        $allowed = match ($j->kind) {
            'prepare' => $slot === 'processed' || preg_match('/^thumbnail-([1-9][0-9]{0,2})$/D', $slot, $m) && ((int) $m[1]) <= config('dossiers.max_pages'),'export' => $slot === 'pdf','ai_page' => $slot === 'image',default => false
        };
        abort_unless($allowed, 422, 'Output slot not permitted.');
        Validator::make(['file' => $file, 'sha256' => $expectedSha], ['file' => 'required|file|max:'.config('dossiers.worker.output_max_kb', 262144), 'sha256' => 'required|regex:/^[a-f0-9]{64}$/'])->validate();
        $mime = $file->getMimeType();
        $image = $j->kind === 'ai_page' || str_starts_with($slot, 'thumbnail-');
        abort_unless($image ? in_array($mime, ['image/png', 'image/jpeg'], true) : $mime === 'application/pdf', 422, 'Invalid output MIME.');
        if ($image) {
            $sz = getimagesize($file->getRealPath());
            $max = $slot === 'image' ? 4500 : 1000;
            abort_unless($sz && max($sz[0], $sz[1]) <= $max, 422, 'Invalid preview dimensions.');
        } else {
            $stream = fopen($file->getRealPath(), 'rb');
            try {
                $head = fread($stream, 1024);
            } finally {
                fclose($stream);
            }abort_unless(str_contains($head, '%PDF-'), 422, 'Missing PDF signature.');
        }
        $hash = hash_file('sha256', $file->getRealPath());
        abort_unless(hash_equals($hash, $expectedSha), 422, 'Upload checksum mismatch.');
        $find = fn () => DossierObject::where('job_id', $j->id)->where('attempt', $j->attempts)->where('slot', $slot)->first();
        $same = function ($old) use ($hash, $file) {
            abort_unless(hash_equals($old->sha256, $hash) && $old->bytes === $file->getSize(), 409, 'Output retry differs.');
            app(DossierObjects::class)->verify($old);

            return $old;
        };
        if ($old = $find()) {
            return $same($old);
        }
        abort_if($j->status === 'succeeded', 409, 'Completed outputs immutable.');
        // Stream remote writes outside DB locks. A private staged candidate has no
        // receipt/published reference until the claim is revalidated under lock.
        $o = app(DossierObjects::class)->store($file, $image ? 'preview' : 'derivative', ['metadata' => ['candidate_job' => $j->id, 'candidate_attempt' => $j->attempts, 'input_hash' => $j->input_hash]]);
        try {
            $receipt = DB::transaction(function () use ($j, $o, $slot, $claim, $find, $same) {
                $current = DossierWorkerJob::lockForUpdate()->findOrFail($j->id);
                $this->owner($current, ...[...$claim, true]);
                if ($old = $find()) {
                    return $same($old);
                }abort_if($current->status === 'succeeded', 409);
                $o->update(['job_id' => $j->id, 'attempt' => $j->attempts, 'slot' => $slot]);

                return $o;
            }, 3);
            if ($receipt->id !== $o->id) {
                app(DossierObjects::class)->deleteUnreferencedStaged($o->fresh());
            }

            return $receipt;
        } catch (\Throwable $e) {
            app(DossierObjects::class)->deleteUnreferencedStaged($o->fresh());
            throw $e;
        }
    }

    private function observeAiResponse(DossierWorkerJob $job, array $claim, array $data): void
    {
        if (! str_starts_with($job->kind, 'ai_') || ! isset($data['result']['response']['data'])) {
            return;
        }
        DB::transaction(function () use ($job, $claim, $data) {
            $j = DossierWorkerJob::lockForUpdate()->findOrFail($job->id);
            $this->owner($j, ...[...$claim, true]);
            if ($j->status === 'succeeded') {
                return;
            }
            abort_unless(config('dossiers.ai.enabled'), 409, 'AI disabled.');
            if (! empty($data['result']['mock'])) {
                abort_unless(app()->environment('testing') || (app()->environment('local') && config('dossiers.worker.allow_mock_results')), 422, 'Mock results forbidden.');
            }
            abort_unless(($data['input_hash'] ?? null) === $j->input_hash && ($data['pipeline'] ?? null) === $j->pipeline, 409);
            $r = $data['result'];
            Validator::make($r, ['response.data' => 'required|array', 'response.usage' => 'present|array', 'elapsed_ms' => 'required|integer|min:0|max:3600000'])->validate();
            $usage = [];
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
                if (isset($r['response']['usage'][$key])) {
                    abort_unless(is_int($r['response']['usage'][$key]) && $r['response']['usage'][$key] >= 0, 422);
                    $usage[$key] = $r['response']['usage'][$key];
                }
            }
            $response = ['data' => $r['response']['data'], 'usage' => $usage, 'mock' => (bool) ($r['mock'] ?? false), 'attempt' => $j->attempts, 'worker_id' => $j->worker_id, 'ai_profile' => $j->ai_profile];
            $digest = self::digest($response);
            $seen = $j->result['observations'] ?? [];
            if (in_array($digest, $seen, true)) {
                return;
            }abort_if(count($seen) >= 5, 422, 'Attempt observation limit.');
            $j->update(['result' => ['observations' => [...$seen, $digest]]]);
            $target = $j->kind === 'ai_page' ? DossierAiPage::lockForUpdate()->findOrFail($j->subject_id) : DossierAiRun::lockForUpdate()->findOrFail($j->subject_id);
            $rawField = $j->kind === 'ai_page' ? 'raw' : 'group_result';
            $usageField = $j->kind === 'ai_page' ? 'usage' : 'group_usage';
            $msField = $j->kind === 'ai_page' ? 'elapsed_ms' : 'group_ms';
            $totals = $target->$usageField ?? [];
            foreach ($usage as $k => $v) {
                $totals[$k] = (int) ($totals[$k] ?? 0) + $v;
            }
            $raw = $target->$rawField ?? [];
            $raw['attempts'] = [...($raw['attempts'] ?? []), $response];
            $target->update([$rawField => $raw, $usageField => $totals, $msField => (int) $target->$msField + $r['elapsed_ms']]);
        }, 3);
    }

    public function complete(DossierWorkerJob $job, array $claim, array $data): DossierWorkerJob
    {
        abort_unless(strlen(json_encode($data)) <= 2 * 1024 * 1024, 422, 'Result too large.');
        $this->observeAiResponse($job, $claim, $data);

        return DB::transaction(function () use ($job, $claim, $data) {
            $j = DossierWorkerJob::lockForUpdate()->findOrFail($job->id);
            $this->owner($j, ...[...$claim, true]);
            abort_unless(($data['input_hash'] ?? null) === $j->input_hash && ($data['pipeline'] ?? null) === $j->pipeline, 409, 'Input/pipeline changed.');
            $digest = self::digest($data);
            if ($j->status === 'succeeded') {
                abort_unless(hash_equals($j->result_hash, $digest), 409, 'Completion retry differs.');

                return $j;
            }
            $out = DossierObject::where('job_id', $j->id)->where('attempt', $j->attempts)->get()->keyBy('slot');
            foreach ($out as $o) {
                app(DossierObjects::class)->verify($o);
            }
            $r = $data['result'] ?? [];
            $required = [];
            if ($j->kind === 'prepare') {
                $r = Validator::make($r, ['page_count' => 'required|integer|min:1|max:'.config('dossiers.max_pages'), 'geometry_before' => 'required|array', 'geometry_before.*' => 'required|array|size:7', 'geometry_before.*.*' => 'required|string|max:300', 'geometry_after' => 'required|array', 'validation.qpdf_check_exit' => 'required|in:0', 'validation.reopened_pages' => 'required|integer', 'validation.trial_export_pages' => 'required|integer', 'validation.rasterized' => 'required|boolean', 'normalize_exit' => 'required|in:0,3', 'warnings' => 'present|array', 'qpdf_version' => 'required|string|max:100'])->validate();
                abort_unless(count($r['geometry_before']) === $r['page_count'] && $r['geometry_before'] === $r['geometry_after'] && $r['validation']['reopened_pages'] === $r['page_count'] && $r['validation']['trial_export_pages'] === $r['page_count'] && ! $r['validation']['rasterized'], 422, 'Worker PDF validation incomplete.');
                foreach ($r['geometry_before'] as $geometry) {
                    abort_unless(array_map(fn ($line) => explode(':', $line, 2)[0], $geometry) === ['size', 'rot', 'MediaBox', 'CropBox', 'BleedBox', 'TrimBox', 'ArtBox'], 422, 'Incomplete page geometry.');
                }
                abort_unless($r['normalize_exit'] === 0 ? $r['warnings'] === [] : $r['warnings'] === ['missing_xref_self_entry'], 422, 'Unknown qpdf warning.');
                $required = ['processed', ...array_map(fn ($n) => 'thumbnail-'.$n, range(1, $r['page_count']))];
            } elseif ($j->kind === 'export') {
                $r = Validator::make($r, ['pages' => 'required|integer|min:1', 'qpdf_check_exit' => 'required|in:0', 'reopened' => 'required|boolean', 'manifest_hash' => 'required|string'])->validate();
                abort_unless($r['pages'] === $j->input['expected_pages'] && $r['reopened'] && $r['manifest_hash'] === $j->input['manifest_hash'], 422, 'Export manifest/output mismatch.');
                $required = ['pdf'];
            } elseif ($j->kind === 'ai_page') {
                $required = ['image'];
            }
            $actual = $out->keys()->all();
            sort($actual);
            $sorted = $required;
            sort($sorted);
            abort_unless($actual === $sorted, 422, 'Missing or extra output artifacts.');
            if (str_starts_with($j->kind, 'ai_')) {
                abort_unless(config('dossiers.ai.enabled'), 409, 'AI disabled.');
                if (! empty($r['mock'])) {
                    abort_unless(app()->environment('testing') || (app()->environment('local') && config('dossiers.worker.allow_mock_results')), 422, 'Mock results forbidden.');
                }
                app(DossierAiService::class)->acceptRemote($j, $r, $out);
            } elseif ($j->kind === 'prepare') {
                $source = DossierSource::lockForUpdate()->findOrFail($j->subject_id);
                $thumbs = [];
                foreach (range(1, $r['page_count']) as $p) {
                    $thumbs[(string) $p] = $out['thumbnail-'.$p]->id;
                }
                $source->update(['processed_object_id' => $out['processed']->id, 'processed_sha256' => $out['processed']->sha256, 'processed_path' => $out['processed']->key, 'previews' => $thumbs, 'page_count' => $r['page_count'], 'progress' => $r['page_count'], 'status' => 'ready', 'error' => null, 'processing' => [...$r, 'method' => 'worker-qpdf-object-streams-disable', 'source_sha256' => $source->sha256, 'input_hash' => $j->input_hash]]);
            } else {
                $e = DossierExport::lockForUpdate()->findOrFail($j->subject_id);
                $e->update(['object_id' => $out['pdf']->id, 'path' => $out['pdf']->key, 'sha256' => $out['pdf']->sha256, 'status' => 'ready', 'error' => null]);
            }
            foreach ($out as $o) {
                $o->update(['status' => 'published']);
            }
            $j->update(['status' => 'succeeded', 'result' => $r, 'result_hash' => $digest, 'error_code' => null]);
            if (str_starts_with($j->kind, 'ai_')) {
                DB::table('dossier_ai_control')->where('id', 1)->update(['next_call_at' => now()->addSeconds(10)]);
            }
            if ($j->kind === 'prepare' && config('dossiers.ai.enabled') && config('dossiers.ai.auto_analyze')) {
                app(DossierAiService::class)->request(DossierSource::findOrFail($j->subject_id), DossierSource::findOrFail($j->subject_id)->user_id, true);
            }

            return $j;
        }, 3);
    }

    public function fail(DossierWorkerJob $job, array $claim, string $code, bool $retryable): DossierWorkerJob
    {
        abort_unless(preg_match('/^[a-z0-9_]{1,64}$/D', $code), 422, 'Use a safe error code, not raw file/AI content.');

        return DB::transaction(function () use ($job, $claim, $code, $retryable) {
            $j = DossierWorkerJob::lockForUpdate()->findOrFail($job->id);
            $this->owner($j, ...$claim);
            $retry = $retryable && $j->attempts < config('dossiers.worker.max_attempts', 3);
            $j->update(['status' => $retry ? 'pending' : 'failed', 'lease_until' => null, 'available_at' => now()->addSeconds($j->attempts > 1 ? 120 : 30), 'error_code' => $code]);
            if (! $retry) {
                $this->domainFailure($j);
            }

            return $j;
        });
    }

    private function domainFailure(DossierWorkerJob $j): void
    {
        $reason = match ($j->error_code) {
            'pdf_encrypted' => 'PDF có mã hóa; hệ thống không tự giải mã',
            'pdf_unknown_warning' => 'PDF có cảnh báo cấu trúc chưa được xác minh an toàn',
            'pdf_structure_error','pdf_geometry_unreadable','pdf_derivative_invalid' => 'PDF lỗi cấu trúc hoặc bản xử lý không vượt kiểm tra',
            'pdf_geometry_changed','pdf_trial_export_failed' => 'Bản xử lý khác geometry/số trang hoặc chưa xuất mở lại được',
            'source_checksum_mismatch','source_size_mismatch' => 'File tải xuống không khớp checksum/dung lượng nguồn',
            'pdf_tool_unavailable','qpdf_requires_12_3_2' => 'Worker thiếu công cụ PDF hoặc phiên bản qpdf phù hợp',
            'pdf_timeout' => 'Xử lý PDF quá thời gian, cần kiểm tra worker trước retry',
            'ai_request_failed' => 'AI chưa trả kết quả hợp lệ; kiểm tra cấu hình/kết nối hoặc hạn mức',
            default => 'Worker hồ sơ chưa hoàn tất; kiểm tra trạng thái và log worker'
        };
        $error = $reason.' ('.$j->error_code.'). File nguồn và dữ liệu đã lưu được giữ; có thể retry riêng.';
        if ($j->kind === 'prepare') {
            DossierSource::whereKey($j->subject_id)->where('status', '!=', 'ready')->update(['status' => 'failed', 'error' => $error]);
        } elseif ($j->kind === 'export') {
            DossierExport::whereKey($j->subject_id)->where('status', '!=', 'ready')->update(['status' => 'failed', 'error' => $error]);
        } else {
            if ($j->kind === 'ai_page') {
                DossierAiPage::whereKey($j->subject_id)->update(['status' => 'failed', 'attempts' => $j->attempts, 'error' => $error]);
            }
            DossierAiRun::whereKey($j->input['run_id'])->update(['status' => 'failed', 'error' => $error]);
        }
    }
}
