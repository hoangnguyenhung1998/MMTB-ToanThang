<?php

namespace App\Services;

use App\Models\MachineAssignment;
use App\Models\OcrJob;
use App\Models\ReconciliationPeriod;
use App\Models\ZaloSenderMachineMapping;
use App\Services\Reconciliation\DailyPhotoSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyPhotoBacklogService
{
    private const PROTECTED_REVIEW_STATUSES = ['APPROVED', 'CORRECTED', 'REJECTED'];

    public function __construct(
        private readonly AssetCodeResolver $assetCodes,
        private readonly DailyPhotoExceptionReason $reasons,
        private readonly DailyPhotoCaseService $cases,
        private readonly DailyPhotoPairingService $pairing,
        private readonly DailyPhotoSyncService $sync,
    ) {}

    public function report(array $filters = []): array
    {
        $rows = collect();
        $this->query($filters)->chunkById(500, function (Collection $jobs) use (&$rows, $filters): void {
            $rows->push(...$this->analyse($jobs, $filters));
        });

        $filtered = $rows->when($filters['reason'] ?? null, fn (Collection $items, string $reason) => $items
            ->filter(fn (array $row): bool => in_array($reason, $row['reasons'], true)));

        return [
            'total' => $filtered->count(),
            'by_reason' => $filtered->flatMap(fn (array $row) => $row['reasons'])->countBy()->sortDesc()->all(),
            'by_sender' => $filtered->groupBy('sender_id')->map(fn (Collection $items): array => [
                'sender_name' => $items->first()['sender_name'],
                'total' => $items->count(),
                'mapped' => $items->where('has_effective_mapping', true)->count(),
                'auto_recoverable' => $items->where('auto_recoverable', true)->count(),
            ])->sortByDesc('total')->all(),
            'mapped' => $filtered->where('has_effective_mapping', true)->count(),
            'unmapped' => $filtered->where('has_effective_mapping', false)->count(),
            'auto_recoverable' => $filtered->where('auto_recoverable', true)->count(),
            'manual' => $filtered->where('auto_recoverable', false)->count(),
            'rows' => $filtered->values(),
        ];
    }

    public function senderDashboard(?array $report = null): Collection
    {
        $report ??= $this->report();
        $currentMappings = ZaloSenderMachineMapping::query()->with('machine:id,asset_code')
            ->whereNull('valid_to')->get()->keyBy('sender_id');
        $senders = DB::table('zalo_messages')
            ->whereNotNull('sender_id')
            ->selectRaw('sender_id, MAX(sender_name) sender_name, MAX(received_at) last_received_at')
            ->groupBy('sender_id')->orderByDesc('last_received_at')->limit(500)->get()->keyBy('sender_id');
        foreach ($report['by_sender'] as $senderId => $stats) {
            if ($senderId !== '(không có sender)' && ! $senders->has($senderId)) {
                $senders->put($senderId, (object) [
                    'sender_id' => $senderId,
                    'sender_name' => $stats['sender_name'],
                    'last_received_at' => null,
                ]);
            }
        }

        return $senders->values()->map(function ($sender) use ($report, $currentMappings): array {
            $stats = $report['by_sender'][$sender->sender_id] ?? null;
            $mapping = $currentMappings->get($sender->sender_id);

            return [
                'sender_id' => $sender->sender_id,
                'sender_name' => $sender->sender_name,
                'mapping' => $mapping,
                'waiting' => (int) ($stats['total'] ?? 0),
                'auto_recoverable' => (int) ($stats['auto_recoverable'] ?? 0),
                'manual' => (int) (($stats['total'] ?? 0) - ($stats['auto_recoverable'] ?? 0)),
            ];
        })->sortByDesc('waiting')->values();
    }

    public function recover(array $filters): array
    {
        $summary = ['total' => 0, 'recovered' => 0, 'queued_retry' => 0, 'still_exception' => 0, 'skipped_protected' => 0];
        $caseIds = collect();
        $affected = collect();

        $this->query($filters)->chunkById(200, function (Collection $jobs) use (&$summary, $filters, $caseIds, $affected): void {
            foreach ($this->analyse($jobs, $filters) as $row) {
                if (($filters['reason'] ?? null) && ! in_array($filters['reason'], $row['reasons'], true)) {
                    continue;
                }
                $summary['total']++;
                DB::transaction(function () use ($row, &$summary, $caseIds, $affected): void {
                    $job = OcrJob::query()->lockForUpdate()->findOrFail($row['job']->id);
                    if ($job->status !== 'EXCEPTION' || $this->isProtected($job)) {
                        $summary['skipped_protected']++;

                        return;
                    }
                    if (! $row['auto_recoverable']) {
                        $summary['still_exception']++;

                        return;
                    }

                    if ($row['action'] === 'RETRY') {
                        $focus = collect([
                            ! $row['machine'] ? 'machine' : null,
                            ! $job->extracted_date ? 'date' : null,
                            ! $job->extracted_time ? 'time' : null,
                        ])->filter()->values()->all();
                        $job->update([
                            'status' => 'RETRY',
                            'ocr_initial_extraction' => $this->snapshot($job),
                            'ocr_retry_reason' => implode(',', $focus),
                            'ocr_retry_attempts' => 1,
                            'ocr_final_source' => null,
                            'processed_at' => null,
                            'claimed_by' => null,
                            'claimed_at' => null,
                            'lease_expires_at' => null,
                        ]);
                        $summary['queued_retry']++;

                        return;
                    }

                    $machine = $row['machine'];
                    $mapping = $row['mapping'];
                    $asset = $row['asset'];
                    $method = $row['method'];
                    $metadata = $job->machine_resolution_metadata ?? [];
                    $metadata += [
                        'version' => config('daily_photos.foundation_version'),
                        'capture_datetime_convention' => 'NAIVE_LOCAL_WALL_CLOCK',
                        'capture_timezone' => config('daily_photos.capture_timezone'),
                    ];
                    $metadata['observed_asset_normalized_key'] = $asset['normalized_key'];
                    $metadata['image_asset_resolution_status'] = $asset['status'];
                    $metadata['image_asset_candidate_machine_ids'] = $asset['candidate_machine_ids'];
                    $metadata['sender_machine_mapping_id'] = $mapping?->id;
                    $metadata['backlog_recovered_at'] = now()->toIso8601String();
                    $job->update([
                        'machine_id' => $machine->id,
                        'asset_code' => $asset['observed'] ?: $machine->asset_code,
                        'observed_asset_code' => $asset['observed'],
                        'machine_resolution_method' => $method,
                        'machine_resolution_metadata' => $metadata,
                        'machine_resolved_at' => now(),
                        'status' => 'COMPLETED',
                        'exceptions' => null,
                        'ocr_final_source' => match ($method) {
                            DailyPhotoMachineResolutionService::HUMAN => 'MANUAL',
                            DailyPhotoMachineResolutionService::SENDER_MAPPING,
                            DailyPhotoMachineResolutionService::SENDER_DRIVER_HISTORY => 'SENDER_MAPPING',
                            default => 'OCR_INITIAL',
                        },
                        'processed_at' => now(),
                    ]);
                    $caseIds->push($job->daily_photo_case_id);
                    $case = $this->cases->materialize($job, false);
                    $caseIds->push($case?->id);
                    $affected->push($machine->id.'|'.$job->extracted_date->format('Y-m-d'));
                    $summary['recovered']++;
                }, 3);
            }
        });

        $this->pairing->recomputeMany($caseIds->filter()->unique()->all());
        if ($affected->isNotEmpty()) {
            $affectedDates = $affected->map(fn (string $key): string => explode('|', $key, 2)[1])->unique();
            ReconciliationPeriod::query()->whereIn('status', ['GENERATED', 'REVIEWING'])
                ->whereDate('date_from', '<=', $affectedDates->max())
                ->whereDate('date_to', '>=', \Carbon\Carbon::parse($affectedDates->min())->subDay())
                ->get()
                ->each(fn (ReconciliationPeriod $period) => $this->sync->sync($period));
        }

        return $summary;
    }

    private function query(array $filters): Builder
    {
        return OcrJob::query()->with(['attachment.message', 'machine:id,asset_code'])
            ->where('document_type', 'DAILY_TIMEMARK')
            ->where('status', 'EXCEPTION')
            ->when($filters['sender_id'] ?? null, fn (Builder $query, string $sender) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->where('sender_id', $sender)))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('received_at', '>=', $date)))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('received_at', '<=', $date)))
            ->orderBy('id');
    }

    private function analyse(Collection $jobs, array $filters): Collection
    {
        $senderIds = $jobs->map(fn (OcrJob $job) => $job->attachment?->message?->sender_id)->filter()->unique();
        $mappings = ZaloSenderMachineMapping::query()->with('machine:id,asset_code')
            ->whereIn('sender_id', $senderIds)->orderBy('valid_from')->get()->groupBy('sender_id');

        $rows = $jobs->map(function (OcrJob $job) use ($mappings): array {
            $message = $job->attachment?->message;
            $asset = $this->assetCodes->resolve($job->observed_asset_code ?? $job->asset_code);
            $candidates = $this->mappingCandidates(
                $mappings->get($message?->sender_id) ?? collect(),
                $message?->received_at,
            );
            $mapping = $candidates->count() === 1 ? $candidates->first() : null;
            $frozen = $job->machine_resolution_method && $job->machine_id ? $job->machine : null;
            $machine = $frozen ?: ($asset['status'] === 'MATCHED' ? $asset['machine'] : ($asset['status'] === 'AMBIGUOUS' ? null : $mapping?->machine));
            $method = $frozen
                ? $job->machine_resolution_method
                : ($asset['machine'] ? DailyPhotoMachineResolutionService::IMAGE_ASSET : ($mapping ? DailyPhotoMachineResolutionService::SENDER_MAPPING : null));
            $reasons = $this->currentReasons($job, $asset, $machine, $candidates->count());
            $protected = $this->isProtected($job);
            $completeFields = $machine && $job->extracted_date && $job->extracted_time;
            $retryableFields = ! $completeFields
                && $asset['status'] !== 'AMBIGUOUS'
                && $candidates->count() <= 1
                && (int) $job->ocr_retry_attempts < 1
                && (int) $job->attempts < max(1, (int) config('ocr.max_attempts'));

            return [
                'job' => $job,
                'sender_id' => $message?->sender_id ?: '(không có sender)',
                'sender_name' => $message?->sender_name ?: $message?->sender_id ?: 'Không xác định',
                'asset' => $asset,
                'mapping' => $mapping,
                'machine' => $machine,
                'method' => $method,
                'has_effective_mapping' => (bool) $mapping,
                'reasons' => $reasons,
                'protected' => $protected,
                'recoverable_fields' => $completeFields || $retryableFields,
                'action' => $completeFields ? 'RECOVER' : 'RETRY',
            ];
        });

        $scopeRequested = filled($filters['command_center_id'] ?? null) || filled($filters['project_id'] ?? null);
        $assignments = collect();
        if ($scopeRequested) {
            $dates = $rows->map(fn (array $row) => $row['job']->extracted_date?->format('Y-m-d'))->filter();
            $assignments = MachineAssignment::query()
                ->whereIn('machine_id', $rows->pluck('machine.id')->filter()->unique())
                ->when($dates->isNotEmpty(), fn (Builder $query) => $query
                    ->where('time_in', '<=', $dates->max().' 23:59:59')
                    ->where(fn (Builder $query) => $query->whereNull('time_out')->orWhere('time_out', '>', $dates->min().' 00:00:00')))
                ->when($filters['command_center_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('command_center_id', $id))
                ->when($filters['project_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('project_id', $id))
                ->get()->groupBy('machine_id');
        }

        return $rows->map(function (array $row) use ($scopeRequested, $assignments): array {
            $date = $row['job']->extracted_date?->format('Y-m-d');
            $inScope = ! $scopeRequested || ($row['machine'] && $date && ($assignments->get($row['machine']->id) ?? collect())
                ->contains(fn (MachineAssignment $assignment): bool => $assignment->time_in->lte($date.' 23:59:59')
                    && (! $assignment->time_out || $assignment->time_out->gt($date.' 00:00:00'))));
            $row['auto_recoverable'] = ! $row['protected'] && $inScope && $row['recoverable_fields'];
            $row['in_scope'] = $inScope;

            return $row;
        })->filter(fn (array $row): bool => $row['in_scope'])->values();
    }

    private function mappingCandidates(Collection $history, $receivedAt): Collection
    {
        if (! $receivedAt) {
            return collect();
        }

        $effective = $history->filter(fn (ZaloSenderMachineMapping $mapping): bool => $mapping->valid_from->lte($receivedAt)
            && (! $mapping->valid_to || $mapping->valid_to->gt($receivedAt)))->values();
        if ($effective->isNotEmpty()) {
            return $effective;
        }

        // Backlog-only exception: messages older than the first mapping may use
        // that first mapping, but only when it is uniquely identifiable.
        $first = $history->first();
        if (! $first?->valid_from || ! $receivedAt->lt($first->valid_from)) {
            return collect();
        }

        return $history->filter(fn (ZaloSenderMachineMapping $mapping): bool => $mapping->valid_from->equalTo($first->valid_from))->values();
    }

    private function currentReasons(OcrJob $job, array $asset, $machine, int $mappingCandidates): array
    {
        $reasons = collect($this->reasons->forJob($job));
        if (! $job->extracted_date) {
            $reasons->push('CAPTURE_DATE_MISSING');
        }
        if (! $job->extracted_time) {
            $reasons->push('CAPTURE_TIME_MISSING');
        }
        if ($asset['status'] === 'AMBIGUOUS') {
            $reasons = $reasons->reject(fn (string $reason): bool => in_array($reason, ['MACHINE_OCR_INVALID', 'MACHINE_NOT_FOUND'], true));
            $reasons->push('MACHINE_AMBIGUOUS');
        } elseif ($asset['status'] === 'NOT_FOUND') {
            $reasons->push('MACHINE_OCR_INVALID');
        }
        if (! $machine) {
            $reasons->push(match (true) {
                $asset['status'] === 'AMBIGUOUS', $mappingCandidates > 1 => 'MACHINE_AMBIGUOUS',
                $asset['status'] === 'NOT_FOUND' => 'MACHINE_OCR_INVALID',
                default => 'SENDER_MAPPING_MISSING',
            });
        }

        return $reasons->unique()->values()->all();
    }

    private function isProtected(OcrJob $job): bool
    {
        return $job->reviewed_at !== null
            || in_array($job->review_status, self::PROTECTED_REVIEW_STATUSES, true)
            || $job->machine_resolution_method === DailyPhotoMachineResolutionService::HUMAN;
    }

    private function snapshot(OcrJob $job): array
    {
        return [
            'date' => $job->extracted_date?->format('Y-m-d'),
            'time' => $job->extracted_time,
            'asset_code' => $job->observed_asset_code ?? $job->asset_code,
            'operator_name' => $job->operator_name,
            'phone' => $job->phone,
            'work_location' => $job->work_location,
            'confidence' => (float) $job->confidence,
            'raw_text' => $job->raw_text,
            'image_fingerprint' => data_get($job->daily_metadata, 'image_fingerprint'),
        ];
    }
}
