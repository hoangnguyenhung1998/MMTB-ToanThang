<?php

namespace App\Services;

use App\Models\AiReconciliationJob;
use App\Models\Machine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AiReconciliationDashboardService
{
    public function jobs(array $filters): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->with(['machine:id,asset_code', 'latestSubmission'])
            ->withCount('commands')
            ->latest('work_date')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
    }

    public function summary(array $filters): array
    {
        $query = $this->filteredQuery($filters);

        return [
            'total' => (clone $query)->count(),
            'matched' => $this->outcomeCount($query, 'MATCHED'),
            'warning' => $this->outcomeCount($query, 'WARNING'),
            'exception' => $this->outcomeCount($query, 'EXCEPTION'),
            'waiting_evidence' => (clone $query)->where('status', 'WAITING_EVIDENCE')->count(),
            'failed' => (clone $query)->where('status', 'FAILED')->count(),
        ];
    }

    public function machines()
    {
        return Machine::query()
            ->whereHas('aiReconciliationJobs')
            ->orderBy('asset_code')
            ->get(['id', 'asset_code']);
    }

    private function filteredQuery(array $filters): Builder
    {
        return AiReconciliationJob::query()
            ->when($filters['machine_id'] ?? null, fn (Builder $query, $machineId) => $query->where('machine_id', $machineId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('work_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('work_date', '<=', $date))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['outcome'] ?? null, fn (Builder $query, $outcome) => $query->whereHas(
                'latestSubmission',
                fn (Builder $submission) => $submission->where('outcome', $outcome),
            ));
    }

    private function outcomeCount(Builder $query, string $outcome): int
    {
        return (clone $query)->whereHas(
            'latestSubmission',
            fn (Builder $submission) => $submission->where('outcome', $outcome),
        )->count();
    }
}
