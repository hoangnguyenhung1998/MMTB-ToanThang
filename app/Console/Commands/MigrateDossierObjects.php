<?php

namespace App\Console\Commands;

use App\Models\DossierAiPage;
use App\Models\DossierExport;
use App\Models\DossierObject;
use App\Models\DossierSource;
use App\Services\Dossiers\DossierObjects;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MigrateDossierObjects extends Command
{
    protected $signature = 'dossiers:migrate-objects {--disk= : Explicit destination, e.g. r2} {--execute : Default is read-only dry run} {--backup-verified : Operator has verified DB/file backup}';

    protected $description = 'Copy legacy dossier files, verify SHA/size, bind object references; retain originals and manifests.';

    private function copy(string $key, string $purpose, ?string $expected = null): DossierObject
    {
        $disk = 'dossiers'; // Existing private legacy local disk, never inferred from the new default.
        $legacy = Storage::disk($disk);
        if (! $legacy->exists($key)) {
            throw new \RuntimeException('Legacy referenced file missing; no pointer changed.');
        }
        $stream = $legacy->readStream($key);
        $tmp = tmpfile();
        if (! is_resource($stream) || ! is_resource($tmp)) {
            throw new \RuntimeException('Cannot open migration streams.');
        }
        try {
            stream_copy_to_stream($stream, $tmp);
            fflush($tmp);
            $path = stream_get_meta_data($tmp)['uri'];
            $sha = hash_file('sha256', $path);
            if ($expected && ! hash_equals($expected, $sha)) {
                throw new \RuntimeException('Legacy checksum mismatch; no pointer changed.');
            }
            $old = DossierObject::where('disk', $this->option('disk'))->where('sha256', $sha)->where('metadata->legacy_disk', $disk)->where('metadata->legacy_key', $key)->first();
            if ($old) {
                app(DossierObjects::class)->verify($old);

                return $old;
            }

            return app(DossierObjects::class)->store(new UploadedFile($path, 'migration', null, null, true), $purpose, ['metadata' => ['legacy_disk' => $disk, 'legacy_key' => $key]]);
        } finally {
            fclose($stream);
            fclose($tmp);
        }
    }

    public function handle(): int
    {
        if (! $this->option('disk') || $this->option('disk') === 'dossiers') {
            $this->error('Specify a separate destination disk; legacy disk stays unchanged.');

            return self::FAILURE;
        }
        $counts = ['sources' => DossierSource::whereNull('object_id')->count(), 'exports' => DossierExport::where('status', 'ready')->whereNull('object_id')->count(), 'ai_images' => DossierAiPage::whereNull('image_object_id')->whereNotNull('image_sha256')->count()];
        $this->line(json_encode(['dry_run' => ! $this->option('execute'), 'counts' => $counts]));
        if (! $this->option('execute')) {
            return self::SUCCESS;
        }
        if (! $this->option('backup-verified')) {
            $this->error('Verify backups before --execute --backup-verified.');

            return self::FAILURE;
        }
        config(['dossiers.storage_disk' => $this->option('disk')]);
        // Each subject commits independently; interrupted copies are recovered via legacy metadata.
        foreach (DossierSource::orderBy('id')->cursor() as $s) {
            $bindings = [];
            $objects = [];
            foreach (['object_id' => [$s->path, 'source', $s->sha256], 'processed_object_id' => [$s->processed_path, 'derivative', $s->processed_sha256]] as $field => [$key,$purpose,$hash]) {
                if (! $key || $s->$field) {
                    continue;
                }$o = $this->copy($key, $purpose, $hash);
                $bindings[$field] = $o->id;
                $objects[] = $o;
            }
            $previews = $s->previews ?? [];
            for ($p = 1; $p <= $s->progress; $p++) {
                if (isset($previews[$p])) {
                    continue;
                }$o = $this->copy("thumbnails/{$s->id}/{$p}.png", 'preview');
                $previews[$p] = $o->id;
                $objects[] = $o;
            }
            DB::transaction(function () use ($s, $bindings, $previews, $objects) {
                $current = DossierSource::lockForUpdate()->findOrFail($s->id);
                foreach ($bindings as $field => $id) {
                    if ($current->$field && $id !== $current->$field) {
                        throw new \RuntimeException('Concurrent migration changed reference.');
                    }
                }$current->update([...$bindings, 'previews' => $previews]);
                foreach ($objects as $o) {
                    $o->update(['status' => 'published']);
                }
            });
        }
        foreach (DossierExport::where('status', 'ready')->whereNull('object_id')->cursor() as $e) {
            $o = $this->copy($e->path, 'derivative', $e->sha256);
            DB::transaction(function () use ($e, $o) {
                $current = DossierExport::lockForUpdate()->findOrFail($e->id);
                if (! $current->object_id) {
                    $current->update(['object_id' => $o->id]);
                }$o->update(['status' => 'published']);
            });
        }
        // A historical snapshot can reference an older derivative than its source's
        // current pointer. Copy those bytes too; never rewrite the manifest JSON.
        foreach (DossierExport::cursor() as $export) {
            foreach ($export->manifest as $item) {
                if (empty($item['processed_sha256']) || ! empty($item['processed_object_id'])) {
                    continue;
                }
                $old = DossierObject::where('status', 'published')->where('sha256', $item['processed_sha256'])->where('mime', 'application/pdf')->first();
                if ($old) {
                    app(DossierObjects::class)->verify($old);

                    continue;
                }
                if (empty($item['processed_path'])) {
                    throw new \RuntimeException('Historical manifest lacks processed path; no snapshot substitution permitted.');
                }
                $o = $this->copy($item['processed_path'], 'derivative', $item['processed_sha256']);
                $o->update(['status' => 'published']);
            }
        }
        foreach (DossierAiPage::whereNull('image_object_id')->whereNotNull('image_sha256')->cursor() as $p) {
            $o = $this->copy("ai-images/{$p->run->source->sha256}/{$p->page}-{$p->dpi}.jpg", 'preview', $p->image_sha256);
            DB::transaction(function () use ($p, $o) {
                $current = DossierAiPage::lockForUpdate()->findOrFail($p->id);
                if (! $current->image_object_id) {
                    $current->update(['image_object_id' => $o->id]);
                }$o->update(['status' => 'published']);
            });
        }
        $this->info('Copied and SHA/size verified. Originals, versions and export manifests retained.');

        return self::SUCCESS;
    }
}
