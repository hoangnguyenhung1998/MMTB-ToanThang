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
        private readonly DailyPhotoStoredOcrExtractor $storedOcr,
        private readonly DailyPhotoImageGate $imageGate,
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

    public function recoveryPreview(array $filters = []): array
    {
        $summary = [
            'total_considered' => 0,
            'eligible_recover' => 0,
            'eligible_retry' => 0,
            'recoverable_from_stored_ocr' => 0,
            'recoverable_from_mapping' => 0,
            'requires_ocr_retry' => 0,
            'still_manual' => 0,
            'ambiguous' => 0,
            'protected_skipped' => 0,
            'ignored_hour_meter' => 0,
            'ignored_non_daily' => 0,
            'recovered_time' => 0,
            'recovered_date' => 0,
            'recovered_mapping' => 0,
            'ready_to_materialize' => 0,
            'true_conflicts' => 0,
        ];
        $subtypes = collect();
        $samples = collect();

        $this->query($filters)->chunkById(500, function (Collection $jobs) use (&$summary, $subtypes, $samples, $filters): void {
            $rows = $this->analyse($jobs, $filters)
                ->when($filters['reason'] ?? null, fn (Collection $items, string $reason) => $items
                    ->filter(fn (array $row): bool => in_array($reason, $row['reasons'], true)));
            foreach ($rows as $row) {
                $summary['total_considered']++;
                $eligible = $row['auto_recoverable'];
                $recover = $row['action'] === 'RECOVER';
                $retry = $row['action'] === 'RETRY';
                $summary['eligible_recover'] += (int) ($eligible && $recover);
                $summary['eligible_retry'] += (int) ($eligible && $retry);
                $summary['recoverable_from_stored_ocr'] += (int) ($eligible && $recover && $row['stored_fields_applied'] !== []);
                $summary['recoverable_from_mapping'] += (int) ($eligible && $recover && $row['method'] === DailyPhotoMachineResolutionService::SENDER_MAPPING);
                $summary['requires_ocr_retry'] += (int) ($retry && $eligible);
                $summary['still_manual'] += (int) (! $eligible && ! $row['protected']);
                $summary['ambiguous'] += (int) ($row['candidate_conflict'] || in_array('MACHINE_AMBIGUOUS', $row['reasons'], true));
                $summary['protected_skipped'] += (int) $row['protected'];
                $summary['ignored_hour_meter'] += (int) ($eligible && $row['action'] === 'IGNORE' && data_get($row, 'image_classification.document_type') === 'IGNORED_HOUR_METER');
                $summary['ignored_non_daily'] += (int) ($eligible && $row['action'] === 'IGNORE' && data_get($row, 'image_classification.document_type') === 'IGNORED_NON_DAILY_PHOTO');
                $summary['recovered_time'] += (int) ($eligible && in_array('time', $row['stored_fields_applied'], true));
                $summary['recovered_date'] += (int) ($eligible && in_array('date', $row['stored_fields_applied'], true));
                $summary['recovered_mapping'] += (int) ($eligible && $row['method'] === DailyPhotoMachineResolutionService::SENDER_MAPPING);
                $summary['ready_to_materialize'] += (int) ($eligible && $row['diagnostic_subtype'] === 'READY_TO_MATERIALIZE');
                $summary['true_conflicts'] += (int) in_array($row['diagnostic_subtype'], ['TRUE_MACHINE_CONFLICT', 'TRUE_DATE_CONFLICT', 'TRUE_TIME_CONFLICT'], true);
                if ($samples->count() < (int) ($filters['limit'] ?? 20)) {
                    $samples->push([
                        'job_id' => $row['job']->id,
                        'action' => $row['action'],
                        'subtype' => $row['diagnostic_subtype'],
                        'machine' => $row['machine']?->asset_code,
                        'date' => $row['recovered_date'],
                        'time' => $row['recovered_time'],
                        'image_type' => $row['image_classification']['document_type'] ?? null,
                    ]);
                }
                $subtypes->put(
                    $row['diagnostic_subtype'],
                    (int) $subtypes->get($row['diagnostic_subtype'], 0) + 1,
                );
            }
        });
        $subtypes = $subtypes->sortDesc()->all();

        return [
            ...$summary,
            'by_actionable_subtype' => $subtypes,
            'by_loss_stage' => $subtypes,
            'samples' => $samples->all(),
        ];
    }

    public function recover(array $filters): array
    {
        $summary = [
            'total' => 0,
            'recovered' => 0,
            'queued_retry' => 0,
            'still_exception' => 0,
            'skipped_protected' => 0,
            'eligibility_changed' => 0,
            'ignored_hour_meter' => 0,
            'ignored_non_daily' => 0,
        ];
        $caseIds = collect();
        $affected = collect();

        $this->query($filters)->chunkById(200, function (Collection $jobs) use (&$summary, $filters, $caseIds, $affected): void {
            $rows = $this->analyse($jobs, $filters)
                ->when($filters['reason'] ?? null, fn (Collection $items, string $reason) => $items
                    ->filter(fn (array $row): bool => in_array($reason, $row['reasons'], true)))
                ->values();
            $summary['total'] += $rows->count();
            if ($rows->isEmpty()) {
                return;
            }

            DB::transaction(function () use ($rows, &$summary, $caseIds, $affected): void {
                $locked = OcrJob::query()->whereKey($rows->pluck('job.id')->all())
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                foreach ($rows as $row) {
                    $job = $locked->get($row['job']->id);
                    if (! $job || $job->status !== 'EXCEPTION') {
                        $summary['eligibility_changed']++;

                        continue;
                    }
                    if ($this->stateFingerprint($job) !== $row['state_fingerprint']) {
                        $summary['eligibility_changed']++;

                        continue;
                    }
                    if ($this->isProtected($job)) {
                        $summary['skipped_protected']++;

                        continue;
                    }
                    if (! $row['auto_recoverable']) {
                        $summary['still_exception']++;

                        continue;
                    }

                    if ($row['action'] === 'IGNORE') {
                        $classification = $row['image_classification'];
                        $previousState = $this->snapshot($job);
                        $oldCase = $row['job']->dailyPhotoCaseEvidence?->dailyPhotoCase;
                        if ($oldCase?->machine_id && $oldCase?->work_date) {
                            $caseIds->push($oldCase->id);
                            $affected->push($oldCase->machine_id.'|'.$oldCase->work_date->format('Y-m-d'));
                        }
                        $this->cases->detach($job);
                        $job->refresh();
                        $dailyMetadata = $job->daily_metadata ?? [];
                        data_set($dailyMetadata, 'image_classification', [
                            ...$classification,
                            'classified_at' => now()->toIso8601String(),
                            'previous_state' => $previousState,
                        ]);
                        $job->update([
                            'document_type' => $classification['document_type'],
                            'status' => 'COMPLETED',
                            'review_status' => 'AUTO_APPROVED',
                            'machine_id' => null,
                            'machine_resolution_method' => null,
                            'machine_resolved_at' => null,
                            'exceptions' => null,
                            'error_message' => null,
                            'daily_metadata' => $dailyMetadata,
                            'processed_at' => now(),
                            'claimed_by' => null,
                            'claimed_at' => null,
                            'lease_expires_at' => null,
                        ]);
                        $summary[$classification['document_type'] === 'IGNORED_HOUR_METER' ? 'ignored_hour_meter' : 'ignored_non_daily']++;

                        continue;
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

                        continue;
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
                    $dailyMetadata = $job->daily_metadata ?? [];
                    $final = data_get($dailyMetadata, 'ocr_recovery.final_chosen_result', $this->snapshot($job));
                    $final['date'] = $row['recovered_date'];
                    $final['time'] = $job->extracted_time ?? $row['recovered_time'];
                    $final['asset_code'] = $job->observed_asset_code ?? $job->asset_code ?? $row['stored_extraction']['machine'];
                    data_set($dailyMetadata, 'ocr_recovery.final_chosen_result', $final);
                    data_set($dailyMetadata, 'ocr_recovery.source', $row['stored_fields_applied'] === [] ? 'BACKLOG_RECOVERY' : 'STORED_REPARSE');
                    $job->update([
                        'machine_id' => $machine->id,
                        'asset_code' => $asset['observed'] ?: $machine->asset_code,
                        'observed_asset_code' => $asset['observed'],
                        'machine_resolution_method' => $method,
                        'machine_resolution_metadata' => $metadata,
                        'machine_resolved_at' => now(),
                        'extracted_date' => $row['recovered_date'],
                        'extracted_time' => $job->extracted_time ?? $row['recovered_time'],
                        'shift' => $this->classifyShift($job->extracted_time ?? $row['recovered_time']),
                        'status' => 'COMPLETED',
                        'exceptions' => null,
                        'daily_metadata' => $dailyMetadata,
                        'ocr_final_source' => match ($method) {
                            DailyPhotoMachineResolutionService::HUMAN => 'MANUAL',
                            DailyPhotoMachineResolutionService::SENDER_MAPPING,
                            DailyPhotoMachineResolutionService::SENDER_DRIVER_HISTORY => 'SENDER_MAPPING',
                            default => $row['stored_fields_applied'] === [] ? 'OCR_INITIAL' : 'STORED_REPARSE',
                        },
                        'processed_at' => now(),
                    ]);
                    $caseIds->push($job->daily_photo_case_id);
                    $case = $this->cases->materialize($job, false, $row['candidate_assignments']);
                    $caseIds->push($case?->id);
                    $affected->push($machine->id.'|'.$job->extracted_date->format('Y-m-d'));
                    $summary['recovered']++;
                }
            }, 3);
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
        return OcrJob::query()->with([
            'attachment.message',
            'machine:id,asset_code',
            'dailyPhotoCaseEvidence.dailyPhotoCase',
        ])
            ->where('document_type', 'DAILY_TIMEMARK')
            ->where('status', 'EXCEPTION')
            ->when($filters['job'] ?? null, fn (Builder $query, int|string $id) => $query->whereKey($id))
            ->when($filters['sender_id'] ?? null, fn (Builder $query, string $sender) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->where('sender_id', $sender)))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('received_at', '>=', $date)))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attachment.message', fn (Builder $message) => $message->whereDate('received_at', '<=', $date)))
            ->orderBy('id');
    }

    public function analyse(Collection $jobs, array $filters = []): Collection
    {
        $senderIds = $jobs->map(fn (OcrJob $job) => $job->attachment?->message?->sender_id)->filter()->unique();
        $mappings = ZaloSenderMachineMapping::query()->with('machine:id,asset_code')
            ->whereIn('sender_id', $senderIds)->orderBy('valid_from')->get()->groupBy('sender_id');

        $rows = $jobs->map(function (OcrJob $job) use ($mappings): array {
            $message = $job->attachment?->message;
            $imageClassification = $this->imageGate->classifyStored($job);
            $stored = $this->storedOcr->extract($job);
            $asset = $this->assetCodes->resolve($job->observed_asset_code ?? $job->asset_code ?? $stored['machine']);
            if ($stored['machine_conflict']) {
                $asset = [
                    ...$asset,
                    'status' => 'AMBIGUOUS',
                    'machine' => null,
                    'candidate_asset_codes' => $stored['machine_candidates'],
                ];
            }
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
            $persistedDate = $job->extracted_date?->format('Y-m-d');
            if ($persistedDate && $message?->received_at && $persistedDate > $message->received_at->toDateString()) {
                $persistedDate = null;
            }
            $recoveredDate = $persistedDate ?? $stored['date'];
            $recoveredTime = $job->extracted_time ?? $stored['time'];
            $candidateConflict = $stored['machine_conflict'] || $stored['date_conflict'] || $stored['time_conflict'];
            $completeFields = $machine && $recoveredDate && $recoveredTime && ! $candidateConflict;
            $retryableFields = ! $completeFields
                && ! $candidateConflict
                && $asset['status'] !== 'AMBIGUOUS'
                && $candidates->count() <= 1
                && (int) $job->ocr_retry_attempts < 1
                && (int) $job->attempts < max(1, (int) config('ocr.max_attempts'));

            $membership = $job->dailyPhotoCaseEvidence;
            $retryValueNotMerged = $this->retryValueNotMerged($job, $stored);

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
                'recoverable_fields' => (bool) $imageClassification || $completeFields || $retryableFields,
                'action' => $imageClassification ? 'IGNORE' : ($completeFields ? 'RECOVER' : 'RETRY'),
                'image_classification' => $imageClassification,
                'stored_extraction' => $stored,
                'recovered_date' => $recoveredDate,
                'recovered_time' => $recoveredTime,
                'stored_fields_applied' => collect([
                    ! $persistedDate && $stored['date'] ? 'date' : null,
                    ! $job->extracted_time && $stored['time'] ? 'time' : null,
                    ! $job->machine_id && $stored['machine'] ? 'machine' : null,
                ])->filter()->values()->all(),
                'candidate_conflict' => $candidateConflict,
                'retry_value_not_merged' => $retryValueNotMerged,
                'diagnostic_subtype' => $protected
                    ? 'PROTECTED'
                    : ($imageClassification
                        ? ($imageClassification['document_type'] === 'IGNORED_HOUR_METER' ? 'IGNORED_HOUR_METER' : 'IGNORED_NON_DAILY')
                        : $this->diagnosticSubtype(
                            $job,
                            $stored,
                            $machine,
                            $mapping,
                            $recoveredDate,
                            $recoveredTime,
                            $completeFields,
                            $membership,
                            $protected,
                        )),
                'state_fingerprint' => $this->stateFingerprint($job),
            ];
        });

        $scopeRequested = filled($filters['command_center_id'] ?? null) || filled($filters['project_id'] ?? null);
        $dates = $rows->pluck('recovered_date')->filter();
        $assignments = $dates->isEmpty() ? collect() : MachineAssignment::query()
            ->whereIn('machine_id', $rows->pluck('machine.id')->filter()->unique())
            ->where('time_in', '<=', $dates->max().' 23:59:59')
            ->where(fn (Builder $query) => $query->whereNull('time_out')->orWhere('time_out', '>', $dates->min().' 00:00:00'))
            ->get()->groupBy('machine_id');

        return $rows->map(function (array $row) use ($scopeRequested, $assignments, $filters): array {
            $date = $row['recovered_date'];
            $activeAssignments = $row['machine'] && $date
                ? ($assignments->get($row['machine']->id) ?? collect())->filter(
                    fn (MachineAssignment $assignment): bool => $assignment->time_in->lte($date.' 23:59:59')
                        && (! $assignment->time_out || $assignment->time_out->gt($date.' 00:00:00'))
                )->values()
                : collect();
            $inScope = ! $scopeRequested || $activeAssignments->contains(
                fn (MachineAssignment $assignment): bool => (! filled($filters['command_center_id'] ?? null)
                        || $assignment->command_center_id === (int) $filters['command_center_id'])
                    && (! filled($filters['project_id'] ?? null)
                        || $assignment->project_id === (int) $filters['project_id'])
            );
            $row['candidate_assignments'] = $activeAssignments;
            $row['auto_recoverable'] = ! $row['protected'] && $inScope && $row['recoverable_fields'];
            $row['in_scope'] = $inScope;
            $row['loss_stage'] = $row['diagnostic_subtype'];

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

    private function diagnosticSubtype(
        OcrJob $job,
        array $stored,
        mixed $machine,
        mixed $mapping,
        ?string $recoveredDate,
        ?string $recoveredTime,
        bool $completeFields,
        mixed $membership,
        bool $protected,
    ): string {
        if ($protected) {
            return 'PROTECTED';
        }
        if ($stored['machine_conflict']) {
            return 'TRUE_MACHINE_CONFLICT';
        }
        if ($stored['date_conflict']) {
            return 'TRUE_DATE_CONFLICT';
        }
        if ($stored['time_conflict']) {
            return 'TRUE_TIME_CONFLICT';
        }
        if ($completeFields && $this->retryValueNotMerged($job, $stored)) {
            return 'RETRY_VALUE_NOT_MERGED';
        }
        if ($completeFields && ! $job->machine_id && $mapping) {
            return 'MAPPING_RECOVERABLE';
        }
        if ($completeFields && ($stored['legacy_conflicts'] ?? []) !== []) {
            return 'LEGACY_AGGREGATION_FAILURE';
        }
        if ($completeFields && ($stored['duplicate_equivalent_fields'] ?? []) !== []) {
            return 'DUPLICATE_EQUIVALENT_CANDIDATES';
        }
        if ($completeFields && (! $membership || ! $membership->capture_datetime)) {
            return 'READY_TO_MATERIALIZE';
        }
        if ($completeFields) {
            return 'STALE_EXCEPTION_ONLY';
        }
        if (! $machine) {
            return $mapping ? 'MAPPING_RECOVERABLE' : 'MAPPING_MISSING';
        }
        if (! $recoveredDate && ! $recoveredTime
            && ($stored['date_candidates'] ?? []) === []
            && ($stored['time_candidates'] ?? []) === []) {
            return 'OCR_RECOGNITION_FAILURE';
        }
        if (! $recoveredDate) {
            return 'ACTUALLY_MISSING_DATE';
        }
        if (! $recoveredTime) {
            return 'ACTUALLY_MISSING_TIME';
        }

        return 'OCR_RECOGNITION_FAILURE';
    }

    private function retryValueNotMerged(OcrJob $job, array $stored): bool
    {
        $retry = data_get($job->daily_metadata, 'ocr_recovery.retry_extraction', []);
        if (! is_array($retry) || $retry === []) {
            return false;
        }

        return (! $job->extracted_date && filled($retry['date'] ?? null) && filled($stored['date'] ?? null))
            || (! $job->extracted_time && filled($retry['time'] ?? null) && filled($stored['time'] ?? null))
            || (! $job->machine_id && filled($retry['asset_code'] ?? null) && filled($stored['machine'] ?? null));
    }

    private function stateFingerprint(OcrJob $job): string
    {
        return hash('sha256', serialize([
            $job->status,
            $job->review_status,
            $job->reviewed_at?->format('Y-m-d H:i:s.u'),
            $job->machine_id,
            $job->machine_resolution_method,
            $job->observed_asset_code,
            $job->asset_code,
            $job->extracted_date?->format('Y-m-d'),
            $job->extracted_time,
            $job->exceptions,
            $job->ocr_retry_attempts,
            $job->ocr_retry_reason,
            $job->raw_text,
            $job->ocr_initial_extraction,
            $job->daily_metadata,
        ]));
    }

    private function classifyShift(?string $time): ?string
    {
        if (! $time) {
            return null;
        }
        $minutes = ((int) substr($time, 0, 2) * 60) + (int) substr($time, 3, 2);

        return match (true) {
            $minutes < 660 => 'MORNING',
            $minutes < 810 => 'MIDDAY',
            $minutes < 990 => 'AFTERNOON',
            $minutes <= 1050 => 'AFTERNOON_OT',
            default => 'EVENING_OT',
        };
    }
}
