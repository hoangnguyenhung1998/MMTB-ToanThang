<?php

namespace App\Services\Dossiers\Ai;

use RuntimeException;
use Symfony\Component\Process\Process;

class DossierVision
{
    public function probe(): array
    {
        $configured = config('dossiers.ai.enabled') && config('dossiers.ai.endpoint') && config('dossiers.ai.key') && config('dossiers.ai.model');

        return ['configured' => (bool) $configured, 'provider' => 'journal-compatible-dossiers', 'model' => config('dossiers.ai.model')];
    }

    public function read(string $prompt, ?string $image = null): array
    {
        if (! $this->probe()['configured']) {
            throw new RuntimeException('Chưa cấu hình AI.');
        }
        $p = new Process([config('dossiers.ai.python'), base_path('journal-worker/scripts/dossier-vision.py')]);
        $p->setEnv(['PYTHONIOENCODING' => 'utf-8']);
        $p->setInput(json_encode(['prompt' => $prompt, 'image' => $image, 'configuration' => ['endpoint' => config('dossiers.ai.endpoint'), 'key' => config('dossiers.ai.key'), 'model' => config('dossiers.ai.model'), 'timeout' => config('dossiers.ai.timeout')]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $p->setTimeout(210);
        try {
            $p->run();
        } catch (\Throwable) {
            throw new RuntimeException('AI transport timeout/launch; không có dữ liệu hồ sơ trong log.');
        }
        $result = json_decode($p->getOutput(), true);
        if (! $p->isSuccessful() || ! is_array($result) || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException('AI transport không trả kết quả hợp lệ; kiểm tra cấu hình/hạn mức và retry riêng.');
        }

        return $result;
    }
}
