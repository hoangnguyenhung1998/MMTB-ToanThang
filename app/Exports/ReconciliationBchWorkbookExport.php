<?php

namespace App\Exports;

use App\Models\ReconciliationPeriod;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ReconciliationBchWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly ReconciliationPeriod $period,
        private readonly array $filters = []
    ) {
    }

    public function sheets(): array
    {
        $query = $this->period->rows()
            ->with(['machine', 'project:id,name', 'commandCenter:id,name', 'driver:id,name'])
            ->whereNotNull('command_center_id')
            ->when($this->filters['machine_id'] ?? null, fn ($query, $id) => $query->where('machine_id', $id))
            ->when($this->filters['project_id'] ?? null, fn ($query, $id) => $query->where('project_id', $id))
            ->when($this->filters['command_center_id'] ?? null, fn ($query, $id) => $query->where('command_center_id', $id))
            ->orderBy('command_center_id')
            ->orderBy('machine_id')
            ->orderBy('work_date');

        $usedTitles = [];

        return $query->get()
            ->groupBy('command_center_id')
            ->map(function (Collection $rows) use (&$usedTitles) {
                $title = $this->uniqueTitle($rows->first()->commandCenter?->name ?? 'BCH', $usedTitles);

                return new ReconciliationBchSheet($this->period, $rows, $title);
            })
            ->values()
            ->all();
    }

    private function uniqueTitle(string $name, array &$used): string
    {
        $base = trim(preg_replace('/[\\\\\/?*\[\]:]/u', '-', $name)) ?: 'BCH';
        $base = mb_substr($base, 0, 31);
        $title = $base;
        $suffix = 2;

        while (in_array(mb_strtolower($title), $used, true)) {
            $ending = ' '.$suffix++;
            $title = mb_substr($base, 0, 31 - mb_strlen($ending)).$ending;
        }

        $used[] = mb_strtolower($title);

        return $title;
    }
}
