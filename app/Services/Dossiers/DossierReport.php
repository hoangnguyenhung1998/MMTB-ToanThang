<?php

namespace App\Services\Dossiers;

use App\Models\DossierRecord;
use App\Models\DossierType;
use App\Models\Machine;
use App\Models\MachineAssignment;
use App\Models\MachineDriverHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DossierReport
{
    private array $driverCache = [];

    private array $assignmentCache = [];

    private array $recordCache = [];

    private ?Collection $typeCache = null;

    public function warm(Collection $machines): void
    {
        $ids = $machines->modelKeys();
        $at = $this->status->now()->format('Y-m-d H:i:s');
        $histories = MachineDriverHistory::with('driver')->whereIn('machine_id', $ids)->where('started_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))->get()->filter(fn ($h) => $h->driver);
        $assignments = MachineAssignment::with(['project', 'commandCenter'])->whereIn('machine_id', $ids)->where('time_in', '<=', $at)
            ->where(fn ($q) => $q->whereNull('time_out')->orWhere('time_out', '>', $at))->get();
        foreach ($ids as $id) {
            $this->driverCache[$id] = $histories->where('machine_id', $id)->unique('driver_id')->values();
            $this->assignmentCache[$id] = $assignments->where('machine_id', $id)->values();
            $this->recordCache['machine:'.$id] = collect();
        }
        foreach ($histories->pluck('driver_id')->unique() as $id) {
            $this->recordCache['driver:'.$id] = collect();
        }
        foreach ($assignments->modelKeys() as $id) {
            $this->recordCache['assignment:'.$id] = collect();
        }
        $records = DossierRecord::with(['type', 'versions.source'])->where(function ($q) use ($ids, $histories, $assignments) {
            $q->whereIn('machine_id', $ids)->orWhereIn('driver_id', $histories->pluck('driver_id'))->orWhereIn('assignment_id', $assignments->modelKeys());
        })->get();
        foreach ($records as $r) {
            $key = $r->driver_id ? 'driver:'.$r->driver_id : ($r->assignment_id ? 'assignment:'.$r->assignment_id : 'machine:'.$r->machine_id);
            $this->recordCache[$key]->push($r);
        }
    }

    public function __construct(private DossierStatus $status) {}

    public function drivers(Machine $m): Collection
    {
        if (isset($this->driverCache[$m->id])) {
            return $this->driverCache[$m->id];
        }
        $at = $this->status->now()->format('Y-m-d H:i:s');

        return MachineDriverHistory::with('driver')->where('machine_id', $m->id)->where('started_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get()
            ->filter(fn ($h) => $h->driver)->unique('driver_id')->values();
    }

    public function assignments(Machine $m): Collection
    {
        if (isset($this->assignmentCache[$m->id])) {
            return $this->assignmentCache[$m->id];
        }
        $at = $this->status->now()->format('Y-m-d H:i:s');

        return MachineAssignment::with(['project', 'commandCenter'])->where('machine_id', $m->id)->where('time_in', '<=', $at)
            ->where(fn ($q) => $q->whereNull('time_out')->orWhere('time_out', '>', $at))
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
    }

    public function rows(Machine $m, ?array $selectedDrivers = null): Collection
    {
        $rows = $this->subjectRows('machine', $m->id, $m->dossier_group, $m);
        foreach ($this->drivers($m) as $history) {
            if ($selectedDrivers === null || in_array($history->driver_id, $selectedDrivers, true)) {
                $rows = $rows->concat($this->subjectRows('driver', $history->driver_id, 'driver', $m, $history->driver->name));
            }
        }
        // Assignment-specific documents apply only to this exact effective assignment.
        foreach ($this->assignments($m) as $assignment) {
            $rows = $rows->concat($this->subjectRows('assignment', $assignment->id, $m->dossier_group, $m,
                'Phân công #'.$assignment->id, false));
        }

        return $rows->values();
    }

    public function subjectRows(string $subject, int $id, string $group, ?Machine $m = null, ?string $name = null, bool $required = true): Collection
    {
        $records = $this->recordCache[$subject.':'.$id] ?? DossierRecord::with(['type', 'versions' => fn ($q) => $q->orderBy('id')
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate()), 'versions.source'])->where($subject.'_id', $id)
            ->orderBy('id')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
        $rows = collect();
        $types = $this->typeCache ??= DossierType::orderBy('sort_order')->orderBy('id')->get();
        foreach ($types as $type) {
            $ofType = $records->where('type_id', $type->id);
            $hasCurrent = false;
            foreach ($ofType as $record) {
                $current = $this->status->current($record);
                if ($current) {
                    $hasCurrent = $hasCurrent || ! $current->archived;
                    $rows->push($this->row($m, $subject, $id, $name, $type, $record, $current));
                }
                foreach ($record->versions->filter(fn ($v) => ! $v->confirmed || $v->effective_at > $this->status->now()->format('Y-m-d H:i:s')) as $pending) {
                    $rows->push($this->row($m, $subject, $id, $name, $type, $record, $pending));
                }
            }
            if ($required && in_array($group, $type->required_groups, true) && ! $hasCurrent) {
                $rows->push($this->row($m, $subject, $id, $name, $type, null, null));
            }
        }

        return $rows;
    }

    private function row($m, $subject, $id, $name, $type, $record, $version): array
    {
        return ['machine_id' => $m?->id, 'asset_code' => $m?->asset_code, 'subject' => $subject, 'subject_id' => $id,
            'subject_name' => $name ?? $m?->asset_code, 'type' => $type, 'record' => $record, 'version' => $version,
            ...$this->status->describe($version, $type->warning_days)];
    }

    public function summary(Collection $rows): array
    {
        return $rows->countBy('status')->all();
    }
}
