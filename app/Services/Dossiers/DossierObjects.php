<?php

namespace App\Services\Dossiers;

use App\Models\DossierExport;
use App\Models\DossierObject;
use App\Models\DossierSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DossierObjects
{
    public function store(UploadedFile $file, string $purpose, array $attributes = []): DossierObject
    {
        $sha = hash_file('sha256', $file->getRealPath());
        $bytes = $file->getSize();
        $mime = $file->getMimeType();
        $id = (string) Str::uuid();
        $disk = config('dossiers.storage_disk', 'r2');
        $prefix = trim(config('dossiers.storage_prefix', 'dossiers'), '/');
        if (! preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $prefix)) {
            throw new \RuntimeException('Invalid dossier storage prefix.');
        }
        $key = $prefix.'/'.$purpose.'/'.$id;
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            if (! Storage::disk($disk)->put($key, $stream, ['visibility' => 'private', 'ContentType' => $mime])) {
                throw new \RuntimeException('Storage write failed.');
            }
        } finally {
            fclose($stream);
        }
        try {
            $o = new DossierObject(['id' => $id, 'disk' => $disk, 'key' => $key, 'sha256' => $sha, 'bytes' => $bytes, 'mime' => $mime, 'purpose' => $purpose, ...$attributes]);
            $this->verify($o);
            $o->save();

            return $o;
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($key);
            throw $e;
        }
    }

    public function verify(DossierObject $object): void
    {
        $disk = Storage::disk($object->disk);
        if (! $disk->exists($object->key) || $disk->size($object->key) !== $object->bytes) {
            throw new \RuntimeException('Object size mismatch.');
        }
        $s = $disk->readStream($object->key);
        if (! is_resource($s)) {
            throw new \RuntimeException('Object unavailable.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $s);
            $actual = hash_final($hash);
        } finally {
            fclose($s);
        }
        if (! hash_equals($object->sha256, $actual)) {
            throw new \RuntimeException('Object checksum mismatch.');
        }
    }

    public function descriptor(DossierObject $o): array
    {
        return ['id' => $o->id, 'sha256' => $o->sha256, 'bytes' => $o->bytes, 'mime' => $o->mime];
    }

    public function response(DossierObject $o, string $name, string $disposition = 'inline')
    {
        abort_unless($o->status === 'published', 404);

        return Storage::disk($o->disk)->response($o->key, $name, ['Content-Type' => $o->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox'], $disposition);
    }

    public function source(DossierSource $s, bool $processed = false): DossierObject
    {
        $id = $processed ? $s->processed_object_id : $s->object_id;
        app(DossierService::class)->check((bool) $id, 'Cần chuyển file local cũ sang object storage và xác minh trước khi giao worker.');

        return DossierObject::where('status', 'published')->findOrFail($id);
    }

    public function deleteUnreferencedStaged(DossierObject $o): void
    {
        if ($o->status !== 'staged' || $o->job_id || DossierSource::where('object_id', $o->id)->orWhere('processed_object_id', $o->id)->exists() || DossierExport::where('object_id', $o->id)->exists()) {
            return;
        }
        Storage::disk($o->disk)->delete($o->key);
        $o->delete();
    }
}
