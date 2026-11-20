<?php

namespace App\Services\Dossiers;

use App\Models\DossierAiPage;
use App\Models\DossierSource;
use App\Models\DossierWorkerJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DossierProgress
{
    public function forSources(Collection $sources): Collection
    {
        $sources->loadMissing(['replacementVersion.record', 'latestAiRun' => fn ($q) => $q->withCount([
            'pages', 'pages as ready_pages_count' => fn ($p) => $p->where('status', 'ready'),
            'proposals as needs_review_count' => fn ($p) => $p->whereNull('version_id')->whereColumn('generation', 'dossier_ai_runs.generation'),
        ])]);
        $runs = $sources->pluck('latestAiRun')->filter()->keyBy('id');
        $pages = DossierAiPage::whereIn('run_id', $runs->keys())->pluck('run_id', 'id');
        $jobs = DossierWorkerJob::query()->where(function ($q) use ($sources, $runs, $pages) {
            $q->where(fn ($q) => $q->where('kind', 'prepare')->whereIn('subject_id', $sources->modelKeys()))
                ->orWhere(fn ($q) => $q->where('kind', 'ai_page')->whereIn('subject_id', $pages->keys()))
                ->orWhere(fn ($q) => $q->where('kind', 'ai_group')->whereIn('subject_id', $runs->keys()));
        })->orderByDesc('updated_at')->orderByDesc('created_at')->get();
        $prepare = $jobs->where('kind', 'prepare')->groupBy('subject_id');
        $ai = $jobs->whereIn('kind', ['ai_page', 'ai_group'])->groupBy(fn ($j) => $j->input['run_id']);

        return $sources->map(fn ($source) => $this->row($source, $prepare->get($source->id)?->first(), $ai->get($source->latestAiRun?->id, collect())));
    }

    private function row(DossierSource $source, ?DossierWorkerJob $prepare, Collection $aiJobs): array
    {
        $run = $source->latestAiRun;
        $job = $source->status === 'ready' && $run ? ($aiJobs->firstWhere('status', 'processing') ?? $aiJobs->firstWhere('status', 'pending') ?? $aiJobs->first() ?? $prepare) : $prepare;
        $now = now();
        $processing = $job?->status === 'processing';
        $last = $job ? last($job->attempt_history ?? []) : null;
        $queued = $job?->queued_at ?? $job?->created_at ?? $source->created_at;
        $finished = $last ? Carbon::parse($last['finished_at']) : $job?->updated_at;
        $heartbeat = $job?->updated_at ?? $source->updated_at;
        $stale = $processing && ($heartbeat->lt($now->copy()->subSeconds(90)) || $job->lease_until?->lte($now));
        $count = $source->status === 'ready' && $run ? $run->ready_pages_count : (int) ($source->status === 'ready' ? $source->page_count : ($job?->status === 'pending' ? 0 : ($job?->progress ?? $source->progress)));
        $total = $source->status === 'ready' && $run ? $run->pages_count : $source->page_count;
        $label = match (true) {
            (bool) $source->replacement_cancelled_at => 'Đã hủy',
            $source->status === 'failed' || $run?->status === 'failed' => 'Lỗi',
            $source->status === 'ready' && $run?->paused && $run->status !== 'ready' => 'OCR đang tạm dừng',
            $source->status === 'ready' && $run?->status === 'unconfigured' => 'Chưa cấu hình AI',
            $source->status === 'ready' && in_array($run?->status, ['queued', 'processing'], true) => 'Phân loại / OCR',
            $source->status === 'ready' => 'Hoàn tất',
            $processing => $job->stage === 'thumbnail' ? 'Chuẩn bị trang' : 'Đọc số trang / Chuẩn bị',
            (bool) $job => 'Chờ worker',
            default => 'Đã nhận PDF',
        };
        $pending = ! $source->replacement_cancelled_at && ($source->status === 'queued' || $source->status === 'processing' ||
            ($source->status === 'ready' && ! $run?->paused && in_array($run?->status, ['queued', 'processing'], true)));

        $record = $source->replacementVersion?->record;
        $target = $source->document_target;
        $rowKey = $record ? implode(':', [$record->driver_id ? 'driver' : ($record->assignment_id ? 'assignment' : 'machine'), $record->driver_id ?? $record->assignment_id ?? $record->machine_id, $record->type_id])
            : ($target ? implode(':', [$target['subject'], $target['id'], $target['type_id']]) : null);

        return [
            'name' => $source->original_name, 'machine' => $source->machine?->asset_code ?? $source->resolution,
            'url' => route('dossiers.source', $source), 'label' => $label, 'pending' => $pending, 'stale' => $stale,
            'count' => $count, 'total' => (int) $total, 'received' => $source->created_at,
            'updated' => $heartbeat, 'wait' => (int) max(0, $queued->diffInSeconds($processing || ! $pending ? ($job?->claimed_at ?? $heartbeat) : $now, false)),
            'run' => $job?->claimed_at && $job->status !== 'pending' && ($processing || $last || ! $pending) ? (int) max(0, $job->claimed_at->diffInSeconds($processing ? ($stale ? $heartbeat : $now) : ($finished ?? $heartbeat), false)) : null,
            'attempt' => $job ? max($job->attempts, count($job->attempt_history ?? []) + (int) ($processing || $job->status === 'pending')) : 0,
            'history' => $job?->attempt_history ?? [],
            'error' => $source->error ?? $run?->error, 'review' => $run?->needs_review_count ?? 0,
            'retry' => $source->status === 'failed' && ! $source->replacement_cancelled_at ? route('dossiers.source.retry', $source) : null,
            'row_key' => $rowKey, 'replacing' => (bool) $source->replacement_version_id && ! $source->replacement_cancelled_at && ! $source->versions_exists,
        ];
    }
}
