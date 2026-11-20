<?php

namespace App\Services;

use App\Models\DossierSenderProfile;
use App\Models\Driver;
use App\Models\MachineDriverHistory;
use App\Models\ZaloSenderDriverLink;
use App\Models\ZaloSenderMachineMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DossierZaloRoster
{
    public function identities(): Collection
    {
        $at = now()->timezone(config('dossiers.timezone'))->format('Y-m-d H:i:s');
        $links = ZaloSenderDriverLink::where('valid_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>', $at))->get();

        return $links->map(fn ($link) => ['sender_id' => $link->sender_id, 'driver_id' => $link->driver_id])
            ->concat(DossierSenderProfile::get()->map(fn ($link) => ['sender_id' => $link->sender_id, 'driver_id' => $link->driver_id]))
            ->groupBy('sender_id')->map(fn ($rows) => $rows->pluck('driver_id')->unique()->values());
    }

    // Call only after acquiring the sender row and stable driver row. All
    // interactive association writers use the same identity/machine check.
    public function guardAssociation(string $sender, int $driver): void
    {
        $identities = $this->identities();
        $siblings = $identities->filter(fn ($ids) => $ids->count() === 1 && $ids->contains($driver))->keys()->push($sender)->unique();
        $machines = ZaloSenderMachineMapping::whereIn('sender_id', $siblings)->whereNull('valid_to')->orderBy('id')->lockForUpdate()->get();
        if ($machines->count() > 1) {
            throw \Illuminate\Validation\ValidationException::withMessages(['sender_id' => 'Liên kết này tạo nhiều máy đang mở cho một tài xế. Cần đối chiếu mapping cũ.']);
        }
    }

    public function snapshot(): array
    {
        $identities = $this->identities();
        $mappings = ZaloSenderMachineMapping::query()->where('valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>', now()))
            ->orderBy('id')->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->get();
        $drivers = Driver::whereIn('id', $identities->flatten()->unique())->get()->keyBy('id');
        $historicalSenders = ZaloSenderMachineMapping::whereIn('sender_id', $identities->keys())->distinct()->pluck('sender_id')->flip();
        $controlled = $historicalSenders->keys()->flatMap(fn ($sender) => $identities->get($sender, collect()));
        $issues = collect();
        $mapped = collect();
        foreach ($mappings->groupBy('sender_id') as $sender => $rows) {
            $ids = $identities->get($sender, collect());
            if ($ids->count() !== 1 || $rows->count() !== 1 || ! $drivers->has($ids->first())) {
                $issues->push(['sender_id' => $sender, 'machine_ids' => $rows->pluck('machine_id')->all(), 'reason' => 'Cần đối chiếu liên kết Zalo và tài xế.']);

                continue;
            }
            $mapping = $rows->first();
            $mapped->push(new MachineDriverHistory(['machine_id' => $mapping->machine_id, 'driver_id' => $ids->sole()]));
            $mapped->last()->setRelation('driver', $drivers->get($ids->sole()));
        }
        foreach ($mapped->groupBy('driver_id') as $driver => $rows) {
            if ($rows->pluck('machine_id')->unique()->count() > 1) {
                $issues->push(['sender_id' => null, 'machine_ids' => $rows->pluck('machine_id')->unique()->all(), 'reason' => 'Tài xế #'.$driver.' có nhiều máy đang mở.']);
                $mapped = $mapped->where('driver_id', '!=', $driver);
            }
        }

        $at = now()->timezone(config('dossiers.timezone'))->format('Y-m-d H:i:s');
        $legacy = MachineDriverHistory::with('driver')->whereNotIn('driver_id', $controlled)
            ->where('started_at', '<=', $at)->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $at))
            ->orderBy('id')->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->get()->filter(fn ($row) => $row->driver);
        foreach ($legacy->groupBy('driver_id') as $driver => $rows) {
            $machines = $rows->pluck('machine_id')->unique()->values();
            if ($machines->count() > 1) {
                $issues->push(['sender_id' => null, 'machine_ids' => $machines->all(), 'reason' => 'Phân công cũ của tài xế #'.$driver.' có nhiều máy đang mở.']);

                continue;
            }
            $mapped->push($rows->first());
        }

        return ['rows' => $mapped->unique(fn ($row) => [$row->machine_id, $row->driver_id])->values(),
            'controlled' => $controlled->unique()->values(), 'issues' => $issues];
    }
}
