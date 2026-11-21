<?php

namespace App\Console\Commands;

use App\Models\DossierAiRun;
use App\Models\DossierObject;
use App\Models\DossierSource;
use App\Models\DossierWorkerJob;
use App\Models\User;
use App\Services\Dossiers\Ai\DossierAiSchema;
use App\Services\Dossiers\Ai\DossierAiService;
use App\Services\Dossiers\DossierAudit;
use App\Services\Dossiers\DossierObjects;
use App\Services\Dossiers\DossierRemoteJobs;
use App\Services\Dossiers\DossierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecoverDossierBlank extends Command
{
    protected $signature = 'dossiers:recover-blank {source} {run} {page} {--actor=} {--apply= : Exact receipt from preview}';

    protected $description = 'Preview or recover one blank page from a verified saved worker response';

    public function handle(DossierObjects $objects, DossierAiService $ai): int
    {
        try {
            $outcome = DB::transaction(function () use ($objects, $ai) {
                $check = app(DossierService::class);
                $run = DossierAiRun::findOrFail($this->argument('run'));
                DB::table('dossier_ai_control')->where('id', 1)->lockForUpdate()->firstOrFail();
                $page = $run->pages()->where('page', $this->argument('page'))->firstOrFail();
                $jobs = DossierWorkerJob::where('kind', 'ai_page')->where('subject_id', (string) $page->id)->lockForUpdate()->get();
                $source = DossierSource::lockForUpdate()->findOrFail($this->argument('source'));
                $run = DossierAiRun::lockForUpdate()->findOrFail($run->id);
                $page->refresh();
                $check->check($run->source_id === $source->id && $source->status === 'ready', 'Sai run/hồ sơ hoặc nguồn chưa sẵn sàng.');
                if (isset($page->raw['blank_recovery']) && $page->status === 'ready') {
                    return ['status' => 'already_recovered', 'page' => $page->page];
                }
                $check->check(in_array($page->status, ['failed', 'pending'], true) && ! $run->paused && $run->status !== 'ready', 'Trang/run không ở trạng thái có thể phục hồi.');
                $check->check(! $run->pages()->where('page', '<', $page->page)->where('status', '!=', 'ready')->exists(), 'Có trang trước chưa hoàn tất.');
                $original = $objects->source($source);
                $check->check(hash_equals($source->sha256, $original->sha256) && $run->pipeline === DossierAiSchema::VERSION,
                    'Checksum nguồn hoặc phiên bản schema của run không khớp.');
                $objects->verify($original);
                $pdf = $objects->source($source, (bool) $source->processed_object_id);
                if ($pdf->id !== $original->id) {
                    $check->check(hash_equals((string) $source->processed_sha256, $pdf->sha256), 'Checksum bản xử lý không khớp.');
                    $objects->verify($pdf);
                }
                $candidates = [];
                foreach ($jobs as $job) {
                    $check->check($job->status !== 'processing' || ! $job->lease_until?->gt(now()), 'Worker còn giữ lease cho trang này.');
                    if (isset($job->input['proposal_id']) || ($job->input['run_id'] ?? null) !== $run->id ||
                        ($job->input['page'] ?? null) !== $page->page ||
                        ($job->input['dpi'] ?? null) !== $page->dpi ||
                        ($job->input['page_revision'] ?? null) !== $page->revision ||
                        $job->pipeline !== DossierRemoteJobs::PIPELINE ||
                        DossierRemoteJobs::digest($job->input) !== $job->input_hash ||
                        DossierRemoteJobs::digest($job->input['objects']['pdf'] ?? []) !== DossierRemoteJobs::digest($objects->descriptor($pdf))) {
                        continue;
                    }
                    foreach ($page->raw['attempts'] ?? [] as $stored) {
                        $hash = DossierRemoteJobs::digest($stored);
                        if (($stored['data']['kind'] ?? null) !== 'blank' || ! in_array($stored['data']['text'] ?? null, [null, ''], true)
                            || empty($stored['attempt']) || ! array_key_exists('text', $stored['data'])
                            || ($stored['ai_profile'] ?? null) !== ($run->context['ai_profile'] ?? null)
                            || ! in_array($hash, $job->result['observations'] ?? [], true)) {
                            continue;
                        }
                        $image = DossierObject::where('job_id', $job->id)->where('attempt', $stored['attempt'])->where('slot', 'image')
                            ->where('status', 'staged')->lockForUpdate()->first();
                        if (($image?->metadata['input_hash'] ?? null) === $job->input_hash && str_starts_with($image->mime, 'image/')) {
                            $candidates[] = [$job, $image, $stored, $hash];
                        }
                    }
                }
                $check->check((bool) $candidates, 'Thiếu phản hồi blank, hash quan sát, phiên bản trang hoặc ảnh có receipt đúng lần xử lý.');
                usort($candidates, fn ($a, $b) => $b[2]['attempt'] <=> $a[2]['attempt'] ?: strcmp($a[0]->id, $b[0]->id));
                [$job, $image, $stored, $hash] = $candidates[0];
                $normalized = app(DossierAiSchema::class)->page($stored['data']);
                $objects->verify($image);
                $receipt = DossierRemoteJobs::digest([$source->id, $source->sha256, $source->revision, $run->id, $run->pipeline, $page->id, $page->revision, $job->input_hash, $image->id, $image->sha256, $hash]);
                if (! $this->option('apply')) {
                    return ['status' => 'preview', 'page' => $page->page, 'attempt' => $stored['attempt'], 'receipt' => $receipt];
                }
                $actor = User::find($this->option('actor'));
                $check->check($actor && Gate::forUser($actor)->allows('manage-dossiers'), 'Cần --actor là tài khoản có quyền hồ sơ.');
                $check->check(hash_equals($receipt, $this->option('apply')), 'Preview đã thay đổi, chạy lại.');
                $jobs->whereIn('status', ['pending', 'processing', 'failed'])->each(fn ($j) => $j->update(['status' => 'cancelled', 'claim_token' => null, 'lease_until' => null]));
                $image->update(['status' => 'published']);
                $page->update(['status' => 'ready', 'result' => $normalized, 'image_object_id' => $image->id, 'image_sha256' => $image->sha256, 'error' => null,
                    'raw' => [...$page->raw, 'blank_recovery' => $receipt]]);
                $run->update(['status' => 'queued', 'error' => null, 'available_at' => null]);
                DossierAudit::write('ai_blank_recovered', $page, $actor->id, ['job_id' => $job->id, 'response_hash' => $hash]);
                $ai->schedule($run);

                return ['status' => 'recovered', 'page' => $page->page];
            }, 3);
        } catch (ValidationException $e) {
            $this->error(implode(' ', $e->validator->errors()->all()));

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Không xác minh được bản ghi hoặc bytes nguồn/ảnh. Kiểm tra quyền, ID và storage.');

            return self::FAILURE;
        }
        $this->line(json_encode($outcome, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
