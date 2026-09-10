<?php

namespace App\Services\Reconciliation;

use App\Models\OcrJob;
use App\Models\ReconciliationPeriod;
use App\Services\DailyPhotoCaseService;
use App\Services\DailyPhotoMachineResolutionService;
use App\Services\DailyPhotoPairingService;
use Illuminate\Support\Facades\DB;

class DailyPhotoResyncService
{
    public function sync(ReconciliationPeriod $period, ?int $commandCenterId = null): array
    {
        return DB::transaction(function () use ($period, $commandCenterId): array {
            $period = ReconciliationPeriod::query()->lockForUpdate()->findOrFail($period->id);
            abort_unless(config('daily_photos.enabled') && in_array($period->status, ['GENERATED', 'REVIEWING'], true), 409);
            $jobs = OcrJob::query()->with('machine.assignments')->where('document_type', 'DAILY_TIMEMARK')
                ->whereIn('status', ['COMPLETED', 'EXCEPTION'])->where('review_status', '!=', 'REJECTED')
                ->whereDate('extracted_date', '>=', $period->date_from)->whereDate('extracted_date', '<=', $period->date_to)
                ->when($commandCenterId, fn ($query) => $query->where(function ($query) use ($commandCenterId, $period): void {
                    $query->whereNull('machine_id')->orWhereHas('machine.assignments', fn ($query) => $query
                        ->where('command_center_id', $commandCenterId)
                        ->where('time_in', '<=', $period->date_to->copy()->endOfDay())
                        ->where(fn ($query) => $query->whereNull('time_out')->orWhere('time_out', '>', $period->date_from->copy()->startOfDay())));
                }))
                ->orderBy('id')->lockForUpdate()->get();
            $exceptions = [];
            $caseIdsToRecompute = collect();
            foreach ($jobs as $job) {
                $resolution = null;
                // Existing resolved machines are immutable. Unresolved legacy OCR may
                // use its own code or the mapping effective at receipt, never today's default.
                if (! $job->machine_id) {
                    $resolution = app(DailyPhotoMachineResolutionService::class)->resolve(
                        $job, $job->observed_asset_code ?? $job->asset_code,
                        $job->extracted_date->toDateString(), $job->extracted_time,
                    );
                }
                $machine = $job->machine ?? ($resolution['machine'] ?? null);
                $date = $job->extracted_date->format('Y-m-d');
                $at = $job->extracted_time ? $date.' '.$job->extracted_time : null;
                $machine?->loadMissing('assignments');
                $upperBound = \Carbon\Carbon::parse($at ?? $date.' 23:59:59');
                $lowerBound = \Carbon\Carbon::parse($at ?? $date.' 00:00:00');
                $assignments = $machine?->assignments
                    ->filter(fn ($assignment) => $assignment->time_in->lte($upperBound)
                        && (! $assignment->time_out || $assignment->time_out->gt($lowerBound)))
                    ->values() ?? collect();
                if ($commandCenterId) {
                    if (! $assignments->contains('command_center_id', $commandCenterId)) {
                        continue;
                    }
                    if ($assignments->count() !== 1) {
                        $exceptions[$machine->id.'|'.$date] = true;

                        continue;
                    }
                }
                $recoverable = ! $job->reviewed_at && $machine
                    && (float) $job->confidence >= (float) config('ocr.minimum_confidence')
                    && ! array_diff($job->exceptions ?? [], ['MISSING_TIME', 'MISSING_ASSET_CODE', 'UNKNOWN_ASSET_CODE']);
                if (! $machine || ($job->status !== 'COMPLETED' && ! $recoverable)) {
                    $exceptions[($machine?->id ?? 'job:'.$job->id).'|'.$date] = true;

                    continue;
                }
                if ($resolution && $recoverable) {
                    $job->update([
                        'machine_id' => $machine->id, 'machine_resolution_method' => $resolution['method'],
                        'machine_resolution_metadata' => $resolution['metadata'], 'machine_resolved_at' => now(),
                        'observed_asset_code' => $resolution['observed_asset_code'], 'asset_code' => $resolution['legacy_asset_code'],
                        'sender_driver_link_id' => $resolution['sender_driver_link_id'],
                        'machine_driver_history_id' => $resolution['machine_driver_history_id'],
                    ]);
                }
                if ($recoverable && $job->status !== 'COMPLETED') {
                    $job->update(['status' => 'COMPLETED', 'exceptions' => $job->extracted_time ? null : ['MISSING_TIME']]);
                }
                $caseIdsToRecompute->push($job->daily_photo_case_id);
                $case = app(DailyPhotoCaseService::class)->materialize($job, false);
                $caseIdsToRecompute->push($case?->id);
                if ($assignments->count() !== 1) {
                    $exceptions[$machine->id.'|'.$date] = true;
                }
            }
            app(DailyPhotoPairingService::class)->recomputeMany($caseIdsToRecompute->filter()->unique()->all());
            $result = app(DailyPhotoSyncService::class)->sync($period, commandCenterId: $commandCenterId);
            $result['exception'] += count($exceptions);

            return $result;
        }, 3);
    }
}
