<?php

namespace App\Services;

use App\Models\DailyPhotoCase;
use App\Models\DailyPhotoCaseEvidence;
use App\Models\DailyPhotoInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyPhotoPairingService
{
    public function recompute(DailyPhotoCase|int $case): DailyPhotoCase
    {
        $caseId = $case instanceof DailyPhotoCase ? $case->id : $case;
        $this->recomputeMany([$caseId]);

        return DailyPhotoCase::query()->with(['evidenceMemberships', 'intervals'])->findOrFail($caseId);
    }

    public function recomputeMany(array $caseIds): void
    {
        $caseIds = collect($caseIds)->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if ($caseIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($caseIds): void {
            $cases = DailyPhotoCase::query()
                ->whereKey($caseIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($cases as $case) {
                $this->recomputeLocked($case);
            }
        }, 3);
    }

    private function recomputeLocked(DailyPhotoCase $case): void
    {
        $memberships = DailyPhotoCaseEvidence::query()
            ->with('ocrJob:id,daily_metadata')
            ->where('daily_photo_case_id', $case->id)
            ->orderBy('capture_datetime')
            ->orderBy('ocr_job_id')
            ->lockForUpdate()
            ->get();

        $ambiguity = $this->detectAmbiguity($memberships);
        if ($ambiguity['codes'] !== []) {
            $this->replaceIntervalsIfChanged($case, []);
            $this->updateMembershipStates($memberships, [], $ambiguity['evidence']);
            $this->updateCase(
                $case,
                DailyPhotoCase::STATUS_PAIRING_AMBIGUOUS,
                $memberships,
                [],
                $ambiguity,
            );

            return;
        }

        $intervals = [];
        $pairedMembershipIds = [];
        for ($index = 0; $index + 1 < $memberships->count(); $index += 2) {
            $start = $memberships[$index];
            $end = $memberships[$index + 1];
            if ($start->capture_datetime->greaterThanOrEqualTo($end->capture_datetime)) {
                $ambiguity = [
                    'codes' => ['INVALID_ORDER'],
                    'evidence' => [
                        $start->id => 'INVALID_ORDER',
                        $end->id => 'INVALID_ORDER',
                    ],
                    'details' => [],
                ];
                $this->replaceIntervalsIfChanged($case, []);
                $this->updateMembershipStates($memberships, [], $ambiguity['evidence']);
                $this->updateCase($case, DailyPhotoCase::STATUS_PAIRING_AMBIGUOUS, $memberships, [], $ambiguity);

                return;
            }

            $sequence = count($intervals) + 1;
            $intervals[] = [
                'daily_photo_case_id' => $case->id,
                'sequence' => $sequence,
                'start_evidence_id' => $start->id,
                'end_evidence_id' => $end->id,
                'raw_start_at' => $start->capture_datetime->format('Y-m-d H:i:s'),
                'raw_end_at' => $end->capture_datetime->format('Y-m-d H:i:s'),
                'raw_start_time' => $start->capture_datetime->format('H:i:s'),
                'raw_end_time' => $end->capture_datetime->format('H:i:s'),
                'status' => DailyPhotoInterval::STATUS_PAIRED,
                'pairing_policy_version' => config('daily_photos.pairing_policy_version'),
            ];
            $pairedMembershipIds[] = $start->id;
            $pairedMembershipIds[] = $end->id;
        }

        $this->replaceIntervalsIfChanged($case, $intervals);
        $this->updateMembershipStates($memberships, $pairedMembershipIds, []);
        $status = $memberships->isNotEmpty() && $memberships->count() % 2 === 0
            ? DailyPhotoCase::STATUS_READY
            : DailyPhotoCase::STATUS_COLLECTING;
        $this->updateCase($case, $status, $memberships, $intervals, [
            'codes' => $memberships->count() % 2 === 1 ? ['ODD_EVIDENCE_COUNT'] : [],
            'evidence' => [],
            'details' => [],
        ]);
    }

    private function detectAmbiguity(Collection $memberships): array
    {
        $codes = [];
        $evidence = [];
        $details = [];

        $assignmentAmbiguous = $memberships
            ->where('assignment_resolution_status', 'AMBIGUOUS')
            ->values();
        if ($assignmentAmbiguous->isNotEmpty()) {
            $codes[] = 'ASSIGNMENT_AMBIGUOUS';
            $details['assignment_ambiguous_evidence_ids'] = $assignmentAmbiguous->modelKeys();
            foreach ($assignmentAmbiguous as $membership) {
                $evidence[$membership->id] = 'ASSIGNMENT_AMBIGUOUS';
            }
        }

        $duplicateTimestamps = $memberships
            ->groupBy(fn (DailyPhotoCaseEvidence $membership) => $membership->capture_datetime->format('Y-m-d H:i:s'))
            ->filter(fn (Collection $group) => $group->count() > 1);
        if ($duplicateTimestamps->isNotEmpty()) {
            $codes[] = 'DUPLICATE_TIMESTAMP';
            $details['duplicate_timestamps'] = $duplicateTimestamps
                ->map(fn (Collection $group) => $group->pluck('ocr_job_id')->values()->all())
                ->all();
            foreach ($duplicateTimestamps->flatten(1) as $membership) {
                $evidence[$membership->id] = 'DUPLICATE_TIMESTAMP';
            }
        }

        $activeByJob = $memberships->keyBy('ocr_job_id');
        $nearDuplicatePairs = [];
        foreach ($memberships as $membership) {
            foreach ((array) data_get($membership->ocrJob?->daily_metadata, 'near_duplicate_ids', []) as $otherJobId) {
                $other = $activeByJob->get((int) $otherJobId);
                if (! $other || $other->id === $membership->id) {
                    continue;
                }
                $pair = [$membership->ocr_job_id, $other->ocr_job_id];
                sort($pair);
                $nearDuplicatePairs[implode(':', $pair)] = $pair;
                $evidence[$membership->id] = 'NEAR_DUPLICATE';
                $evidence[$other->id] = 'NEAR_DUPLICATE';
            }
        }
        if ($nearDuplicatePairs !== []) {
            $codes[] = 'NEAR_DUPLICATE';
            $details['near_duplicate_job_pairs'] = array_values($nearDuplicatePairs);
        }

        return [
            'codes' => array_values(array_unique($codes)),
            'evidence' => $evidence,
            'details' => $details,
        ];
    }

    private function replaceIntervalsIfChanged(DailyPhotoCase $case, array $desired): void
    {
        $existing = $case->intervals()->lockForUpdate()->get();
        $current = $existing->map(fn (DailyPhotoInterval $interval) => [
            'daily_photo_case_id' => $interval->daily_photo_case_id,
            'sequence' => $interval->sequence,
            'start_evidence_id' => $interval->start_evidence_id,
            'end_evidence_id' => $interval->end_evidence_id,
            'raw_start_at' => $interval->raw_start_at->format('Y-m-d H:i:s'),
            'raw_end_at' => $interval->raw_end_at->format('Y-m-d H:i:s'),
            'raw_start_time' => substr((string) $interval->raw_start_time, 0, 8),
            'raw_end_time' => substr((string) $interval->raw_end_time, 0, 8),
            'status' => $interval->status,
            'pairing_policy_version' => $interval->pairing_policy_version,
        ])->values()->all();

        if ($current === $desired) {
            return;
        }

        $case->intervals()->delete();
        foreach ($desired as $interval) {
            DailyPhotoInterval::query()->create($interval);
        }
    }

    private function updateMembershipStates(Collection $memberships, array $pairedIds, array $ambiguous): void
    {
        foreach ($memberships as $membership) {
            $state = isset($ambiguous[$membership->id])
                ? DailyPhotoCaseEvidence::STATE_AMBIGUOUS
                : (in_array($membership->id, $pairedIds, true)
                    ? DailyPhotoCaseEvidence::STATE_PAIRED
                    : DailyPhotoCaseEvidence::STATE_UNMATCHED);
            $diagnostic = $ambiguous[$membership->id] ?? null;
            if ($membership->pairing_state !== $state || $membership->pairing_diagnostic !== $diagnostic) {
                $membership->update([
                    'pairing_state' => $state,
                    'pairing_diagnostic' => $diagnostic,
                ]);
            }
        }
    }

    private function updateCase(
        DailyPhotoCase $case,
        string $status,
        Collection $memberships,
        array $intervals,
        array $ambiguity,
    ): void {
        $pairedMembershipIds = collect($intervals)
            ->flatMap(fn (array $interval) => [$interval['start_evidence_id'], $interval['end_evidence_id']])
            ->all();
        $ambiguousIds = array_map('intval', array_keys($ambiguity['evidence']));
        $unmatched = $memberships
            ->whereNotIn('id', $pairedMembershipIds)
            ->whereNotIn('id', $ambiguousIds)
            ->pluck('id')
            ->values()
            ->all();

        $case->update([
            'status' => $status,
            'pairing_policy_version' => config('daily_photos.pairing_policy_version'),
            'pairing_diagnostics' => [
                'codes' => $ambiguity['codes'],
                'evidence_count' => $memberships->count(),
                'interval_count' => count($intervals),
                'unmatched_evidence_ids' => $unmatched,
                'ambiguous_evidence_ids' => $ambiguousIds,
                ...$ambiguity['details'],
            ],
            'pairing_computed_at' => now(),
        ]);
    }
}
