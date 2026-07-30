<?php

namespace App\Http\Controllers;

use App\Models\CommandCenter;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class GlobalSearchController extends Controller
{
    private const QUICK_LIMIT = 5;
    private const PAGE_LIMIT = 20;

    public function quick(Request $request): JsonResponse
    {
        $query = $this->normalizedQuery($request);

        if (mb_strlen($query) < 2) {
            return response()->json([
                'query' => $query,
                'total' => 0,
                'groups' => $this->emptyGroups(),
            ]);
        }

        $groups = $this->searchGroups($query, self::QUICK_LIMIT);

        return response()->json([
            'query' => $query,
            'total' => collect($groups)->sum(fn (array $items) => count($items)),
            'groups' => $groups,
            'all_url' => route('global-search.index', ['q' => $query]),
        ]);
    }

    public function index(Request $request): View
    {
        $query = $this->normalizedQuery($request);
        $groups = mb_strlen($query) >= 2
            ? $this->searchGroups($query, self::PAGE_LIMIT)
            : $this->emptyGroups();

        return view('search.index', [
            'query' => $query,
            'groups' => $groups,
            'total' => collect($groups)->sum(fn (array $items) => count($items)),
        ]);
    }

    private function normalizedQuery(Request $request): string
    {
        return trim((string) $request->query('q', ''));
    }

    private function searchGroups(string $term, int $limit): array
    {
        return [
            'machines' => $this->searchMachines($term, $limit),
            'drivers' => $this->searchDrivers($term, $limit),
            'projects' => $this->searchProjects($term, $limit),
            'command_centers' => $this->searchCommandCenters($term, $limit),
        ];
    }

    private function searchMachines(string $term, int $limit): array
    {
        $columns = $this->existingColumns('machines', [
            'asset_code',
            'chassis_no',
            'engine_no',
            'plate_no',
            'machine_type',
            'company',
        ]);

        if ($columns === []) {
            return [];
        }

        return Machine::query()
            ->with([
                'currentDriver',
                'currentAssignment.project',
                'currentAssignment.commandCenter',
            ])
            ->where(fn (Builder $query) => $this->applyLikeSearch($query, $columns, $term))
            ->orderBy('asset_code')
            ->limit($limit)
            ->get()
            ->map(function (Machine $machine) {
                $assignment = $machine->currentAssignment;

                return [
                    'id' => $machine->id,
                    'title' => $machine->asset_code ?: ('Máy #' . $machine->id),
                    'subtitle' => collect([
                        $machine->machine_type,
                        $machine->plate_no,
                        $machine->company,
                    ])->filter()->implode(' · '),
                    'meta' => collect([
                        $assignment?->project?->name,
                        $assignment?->commandCenter?->name,
                        $machine->currentDriver?->name,
                    ])->filter()->implode(' · '),
                    'url' => route('machines.show', $machine),
                    'type' => 'machine',
                ];
            })
            ->all();
    }

    private function searchDrivers(string $term, int $limit): array
    {
        $columns = $this->existingColumns('drivers', [
            'name',
            'phone',
            'citizen_id',
            'identity_number',
            'license_number',
        ]);

        if ($columns === []) {
            return [];
        }

        return Driver::query()
            ->withCount('currentMachines')
            ->where(fn (Builder $query) => $this->applyLikeSearch($query, $columns, $term))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Driver $driver) => [
                'id' => $driver->id,
                'title' => $driver->name ?: ('Tài xế #' . $driver->id),
                'subtitle' => collect([
                    $driver->phone,
                    $driver->citizen_id ?? $driver->identity_number ?? null,
                ])->filter()->implode(' · '),
                'meta' => $driver->current_machines_count > 0
                    ? $driver->current_machines_count . ' máy đang phụ trách'
                    : 'Chưa phụ trách máy',
                'url' => route('drivers.show', $driver),
                'type' => 'driver',
            ])
            ->all();
    }

    private function searchProjects(string $term, int $limit): array
    {
        $columns = $this->existingColumns('projects', [
            'name',
            'code',
            'address',
            'location',
        ]);

        if ($columns === []) {
            return [];
        }

        return Project::query()
            ->where(fn (Builder $query) => $this->applyLikeSearch($query, $columns, $term))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'title' => $project->name ?: ('Dự án #' . $project->id),
                'subtitle' => collect([
                    $project->code ?? null,
                    $project->address ?? $project->location ?? null,
                ])->filter()->implode(' · '),
                'meta' => 'Dự án',
                'url' => route('projects.edit', $project),
                'type' => 'project',
            ])
            ->all();
    }

    private function searchCommandCenters(string $term, int $limit): array
    {
        $columns = $this->existingColumns('command_centers', [
            'name',
            'code',
            'description',
        ]);

        if ($columns === []) {
            return [];
        }

        return CommandCenter::query()
            ->where(fn (Builder $query) => $this->applyLikeSearch($query, $columns, $term))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (CommandCenter $commandCenter) => [
                'id' => $commandCenter->id,
                'title' => $commandCenter->name ?: ('Ban chỉ huy #' . $commandCenter->id),
                'subtitle' => collect([
                    $commandCenter->code ?? null,
                    $commandCenter->description ?? null,
                ])->filter()->implode(' · '),
                'meta' => 'Ban chỉ huy',
                'url' => route('command-centers.edit', $commandCenter->id),
                'type' => 'command_center',
            ])
            ->all();
    }

    private function existingColumns(string $table, array $candidates): array
    {
        return collect($candidates)
            ->filter(fn (string $column) => Schema::hasColumn($table, $column))
            ->values()
            ->all();
    }

    private function applyLikeSearch(Builder $query, array $columns, string $term): void
    {
        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $query->{$method}($column, 'like', '%' . $term . '%');
        }
    }

    private function emptyGroups(): array
    {
        return [
            'machines' => [],
            'drivers' => [],
            'projects' => [],
            'command_centers' => [],
        ];
    }
}
