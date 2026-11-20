<?php

namespace App\Console\Commands;

use App\Services\DossierZaloRoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ResetZaloDrivers extends Command
{
    protected $signature = 'dossiers:reset-drivers {--database=} {--apply=} {--backup=} {--sha256=} {--seed} {--actor=}';

    protected $description = 'Preview, apply the one-time driver reset, or seed drivers from active Zalo mappings';

    public function handle(): int
    {
        $connection = DB::connection();
        $engine = $connection->getDriverName();
        $database = match ($engine) {
            'mysql', 'mariadb' => $connection->selectOne('SELECT DATABASE() AS db')->db,
            'sqlite' => $connection->selectOne('PRAGMA database_list')->file,
            default => null,
        };
        if (! $database || $database !== $this->option('database')) {
            $this->error('Database identity does not match --database.');

            return self::FAILURE;
        }
        $ledger = Schema::hasTable('dossier_driver_resets');
        $done = $ledger && DB::table('dossier_driver_resets')->where('name', 'phase-18.5')->whereNotNull('completed_at')->exists();
        if ($this->option('seed')) {
            $actor = (int) $this->option('actor');
            if (! $done || ! DB::table('users')->where('id', $actor)->exists()) {
                $this->error('Apply the reset first and pass an existing --actor.');

                return self::FAILURE;
            }
            foreach (DB::table('zalo_sender_machine_mappings')->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()))->distinct()->orderBy('machine_id')->pluck('machine_id') as $machine) {
                app(DossierZaloRoster::class)->provisionForMachine((int) $machine, $actor);
            }
            $this->info('Drivers with Zalo ID: '.DB::table('drivers')->whereNotNull('zalo_sender_id')->count());

            return self::SUCCESS;
        }
        if ($done) {
            $this->info('Reset already applied. Use --seed to resume loading; no rows deleted.');

            return self::SUCCESS;
        }
        $plan = $this->inventory();
        $token = hash('sha256', json_encode([$database, $plan], JSON_THROW_ON_ERROR));
        $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line('Preview token: '.$token);
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $backup = $this->option('backup');
        if (! $ledger || ! hash_equals($token, (string) $this->option('apply')) || ! $backup || ! is_file($backup) || filesize($backup) < 1024 ||
            ! hash_equals(hash_file('sha256', $backup), (string) $this->option('sha256'))) {
            $this->error('Migration, current preview token and verified backup SHA256 required.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($plan, $engine) {
            DB::table('dossier_driver_resets')->insertOrIgnore(['name' => 'phase-18.5']);
            if (DB::table('dossier_driver_resets')->where('name', 'phase-18.5')->lockForUpdate()->first()->completed_at) {
                return;
            }
            if ($plan !== $this->inventory()) {
                throw new RuntimeException('Inventory changed; take another backup and preview.');
            }
            if ($engine !== 'sqlite') {
                $this->checkForeignKeys();
            }
            DB::table('dossier_ai_control')->where('id', 1)->lockForUpdate()->first();
            if (DB::table('dossier_jobs')->exists() || DB::table('dossier_worker_jobs')->where('status', 'processing')->exists()) {
                throw new RuntimeException('Pause the dossier worker and wait for processing jobs before reset.');
            }
            $this->reset();
            DB::table('dossier_driver_resets')->where('name', 'phase-18.5')->update(['completed_at' => now(), 'inventory' => json_encode($plan, JSON_THROW_ON_ERROR)]);
        }, 3);
        $this->info('Reset applied. Run separately with --seed --actor=<user ID>.');

        return self::SUCCESS;
    }

    private function inventory(): array
    {
        $records = DB::table('dossier_records')->whereNotNull('driver_id')->pluck('id');
        $versions = DB::table('dossier_versions')->whereIn('record_id', $records)->get(['id', 'source_id']);
        $mixed = DB::table('dossier_versions')->whereIn('source_id', $versions->pluck('source_id'))->whereNotIn('record_id', $records)->distinct()->count('source_id');

        return [
            'drivers' => DB::table('drivers')->count(), 'sender_links' => DB::table('zalo_sender_driver_links')->count(),
            'sender_profiles' => DB::table('dossier_sender_profiles')->count(), 'assignments' => DB::table('machine_driver_histories')->count(),
            'legacy_documents' => DB::table('driver_documents')->count(), 'private_records' => $records->count(), 'private_versions' => $versions->count(),
            'mixed_sources_kept' => $mixed, 'private_only_sources' => $versions->pluck('source_id')->unique()->count() - $mixed,
            'exports_to_remove' => $this->exportIds($versions->pluck('id')->flip()->all())->count(),
            'cached_ocr_runs_to_clear' => DB::table('dossier_ai_runs')->count(), 'machines_kept' => DB::table('machines')->count(),
            'machine_versions_kept' => DB::table('dossier_versions')->count() - $versions->count(),
            'machine_events_kept' => DB::table('machine_events')->count(), 'zalo_machine_mappings_kept' => DB::table('zalo_sender_machine_mappings')->count(),
        ];
    }

    private function exportIds(array $versions)
    {
        return DB::table('dossier_exports')->select(['id', 'manifest'])->orderBy('id')->cursor()->filter(function ($export) use ($versions) {
            foreach (json_decode($export->manifest, true, flags: JSON_THROW_ON_ERROR) as $entry) {
                if (($entry['subject'] ?? null) === 'driver' || isset($versions[$entry['version_id'] ?? 0])) {
                    return true;
                }
            }

            return false;
        })->pluck('id')->collect();
    }

    private function checkForeignKeys(): void
    {
        $allowed = ['machines.current_driver_id', 'machine_driver_histories.driver_id', 'driver_documents.driver_id',
            'reconciliation_rows.driver_id', 'machine_intake_cases.driver_id', 'dossier_records.driver_id',
            'dossier_sender_profiles.driver_id', 'zalo_sender_driver_links.driver_id'];
        $refs = DB::select("SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME) AS ref FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='drivers'");
        if (array_diff(array_column($refs, 'ref'), $allowed)) {
            throw new RuntimeException('Unexpected foreign key references to drivers; stop and review.');
        }
    }

    private function reset(): void
    {
        $records = DB::table('dossier_records')->whereNotNull('driver_id')->pluck('id');
        $personal = DB::table('dossier_versions')->whereIn('record_id', $records)->get(['id', 'source_id']);
        $versions = $personal->pluck('id');
        $exports = $this->exportIds($versions->flip()->all());
        $sources = DB::table('dossier_sources')->where(function ($q) use ($personal, $versions) {
            $q->whereIn('id', $personal->pluck('source_id'))->orWhereIn('replacement_version_id', $versions)->orWhere('document_target->subject', 'driver');
        })->pluck('id');
        $mixed = DB::table('dossier_versions')->whereIn('source_id', $sources)->whereNotIn('record_id', $records)->distinct()->pluck('source_id');
        DB::table('dossier_sources')->whereIn('id', $sources->diff($mixed))->update(['driver_reset_retired_at' => now(), 'document_target' => null]);
        DB::table('dossier_sources')->whereIn('replacement_version_id', $versions)->update(['replacement_version_id' => null]);
        // Cached OCR can include personal fragments even on an unclassified scan.
        DB::table('dossier_worker_jobs')->where(function ($q) use ($exports, $sources, $mixed) {
            $q->whereIn('kind', ['ai_page', 'ai_group'])->orWhere(fn ($q) => $q->where('kind', 'export')->whereIn('subject_id', $exports))
                ->orWhere(fn ($q) => $q->where('kind', 'prepare')->whereIn('subject_id', $sources->diff($mixed)));
        })->update(['status' => 'cancelled', 'claim_token' => null, 'input' => '{}', 'result' => null]);
        DB::table('dossier_ai_proposals')->delete();
        DB::table('dossier_ai_pages')->delete();
        DB::table('dossier_ai_runs')->delete();
        DB::table('dossier_exports')->whereIn('id', $exports)->delete();
        DB::table('dossier_reminders')->whereIn('version_id', $versions)->delete();
        foreach (['replaces_id', 'corrects_id'] as $field) {
            DB::table('dossier_versions')->whereIn($field, $versions)->update([$field => null]);
        }
        DB::table('dossier_versions')->whereIn('id', $versions)->delete();
        DB::table('dossier_records')->whereIn('id', $records)->delete();
        DB::table('activity_logs')->whereIn('event', ['zalo.driver_transferred', 'zalo.sender_linked', 'zalo.sender_link_closed',
            'dossier.driver_assigned', 'dossier.driver_assignment_closed'])->delete();
        DB::table('activity_logs')->where('event', 'like', 'dossier.%')->where(function ($q) use ($versions, $records, $sources, $exports) {
            foreach (['dossier_versions' => $versions, 'dossier_records' => $records, 'dossier_sources' => $sources, 'dossier_exports' => $exports] as $table => $ids) {
                $q->orWhere(fn ($q) => $q->where('properties->entity', $table)->whereIn('properties->id', $ids));
            }
            $q->orWhereIn('properties->entity', ['dossier_ai_proposals', 'dossier_ai_runs', 'dossier_ai_pages', 'dossier_sender_profiles']);
        })->delete();
        DB::table('ocr_jobs')->where(fn ($q) => $q->whereNotNull('sender_driver_link_id')->orWhereNotNull('machine_driver_history_id'))->update(['sender_driver_link_id' => null, 'machine_driver_history_id' => null]);
        $this->removeDriverMetadata('ocr_jobs', 'machine_resolution_metadata', [
            'sender_driver_link_id', 'machine_driver_history_id', 'sender_mapping_review', 'sender_candidate_link_ids', 'sender_candidate_history_ids',
        ]);
        $this->removeDriverMetadata('activity_logs', 'properties', [
            'driver_id', 'driver_name', 'current_driver_id', 'sender_driver_link_id', 'machine_driver_history_id', 'old_driver_id', 'new_driver_id',
        ]);
        foreach (['machines' => 'current_driver_id', 'reconciliation_rows' => 'driver_id', 'machine_intake_cases' => 'driver_id'] as $table => $field) {
            DB::table($table)->whereNotNull($field)->update([$field => null]);
        }
        foreach (['dossier_sender_profiles', 'zalo_sender_driver_links', 'machine_driver_histories', 'driver_documents', 'drivers'] as $table) {
            DB::table($table)->delete();
        }
    }
    private function removeDriverMetadata(string $table, string $column, array $keys): void
    {
        $strip = function (array $values) use (&$strip, $keys): array {
            foreach ($keys as $key) {
                unset($values[$key]);
            }
            foreach ($values as &$value) {
                if (is_array($value)) {
                    $value = $strip($value);
                }
            }

            return $values;
        };
        DB::table($table)->select(['id', $column])->whereNotNull($column)->chunkById(500, function ($rows) use ($table, $column, $strip) {
            foreach ($rows as $row) {
                $old = json_decode($row->{$column}, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($old) && ($new = $strip($old)) !== $old) {
                    DB::table($table)->where('id', $row->id)->update([$column => json_encode($new, JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }

}
