<?php

namespace App\Services\Reconciliation;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ReconciliationOcrImportStore
{
    private Filesystem $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('local');
    }

    public function put(int $userId, int $periodId, array $preview): string
    {
        $token = (string) Str::uuid();

        $this->disk->put(
            $this->path($userId, $periodId, $token),
            json_encode([
                'created_at' => now()->toIso8601String(),
                'user_id' => $userId,
                'period_id' => $periodId,
                'preview' => $preview,
            ], JSON_THROW_ON_ERROR)
        );

        return $token;
    }

    public function get(int $userId, int $periodId, string $token): array
    {
        $path = $this->path($userId, $periodId, $token);

        if (! $this->disk->exists($path)) {
            throw new RuntimeException('Không tìm thấy dữ liệu xem trước. Vui lòng tải file OCR lại.');
        }

        $payload = json_decode($this->disk->get($path), true, 512, JSON_THROW_ON_ERROR);

        if (($payload['user_id'] ?? null) !== $userId || ($payload['period_id'] ?? null) !== $periodId) {
            throw new RuntimeException('Dữ liệu nhập không thuộc phiên làm việc hiện tại.');
        }

        return $payload['preview'] ?? [];
    }

    public function forget(int $userId, int $periodId, string $token): void
    {
        $this->disk->delete($this->path($userId, $periodId, $token));
    }

    private function path(int $userId, int $periodId, string $token): string
    {
        return "reconciliation/ocr-imports/{$userId}/{$periodId}/{$token}.json";
    }
}
