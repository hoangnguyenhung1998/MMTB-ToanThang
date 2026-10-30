<?php

namespace App\Services\Dossiers\Ai;

use App\Models\DossierSource;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class DossierAiImage
{
    public function render(DossierSource $source, int $page, int $dpi): array
    {
        $disk = Storage::disk('dossiers');
        $original = $disk->path($source->path);
        if (! hash_equals($source->sha256, hash_file('sha256', $original))) {
            throw new RuntimeException('Checksum nguồn không khớp.');
        }
        $relative = "ai-images/{$source->sha256}/$page-$dpi";
        $disk->makeDirectory(dirname($relative));
        $path = $disk->path($relative.'.jpg');
        if (! is_file($path)) {
            $tmp = $disk->path($relative.'.'.\Illuminate\Support\Str::uuid());
            try {
                $p = new Process([config('dossiers.pdftoppm'), '-f', (string) $page, '-l', (string) $page, '-r', (string) $dpi, '-scale-to', (string) ($dpi > 200 ? 4500 : 3300), '-singlefile', '-jpeg', '-jpegopt', 'quality=95', $original, $tmp]);
                $p->setTimeout(90)->run();
                if (! $p->isSuccessful() || ! is_file($tmp.'.jpg') || ! getimagesize($tmp.'.jpg')) {
                    throw new RuntimeException('Không tạo được ảnh đọc AI.');
                }
                if (! rename($tmp.'.jpg', $path)) {
                    throw new RuntimeException('Không lưu được ảnh đọc AI.');
                }
            } finally {
                if (is_file($tmp.'.jpg')) {
                    unlink($tmp.'.jpg');
                }
            }
        }

        return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'relative' => $relative.'.jpg'];
    }
}
