<?php

namespace App\Jobs;

use App\Models\DossierSource;
use App\Services\Dossiers\DossierPdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessDossierSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $sourceId)
    {
        $this->onConnection('dossiers')->onQueue(config('dossiers.queue'));
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DossierPdf $pdf): void
    {
        $more = DB::transaction(function () use ($pdf) {
            $s = DossierSource::lockForUpdate()->findOrFail($this->sourceId);
            if ($s->status === 'ready') {
                return false;
            }
            $path = Storage::disk('dossiers')->path($s->path);
            if (! hash_equals($s->sha256, hash_file('sha256', $path))) {
                throw new \RuntimeException('Checksum file nguồn không khớp.');
            }
            if (! $s->page_count) {
                $s->page_count = $pdf->prepare($s);
            }
            $next = $s->progress + 1;
            $pdf->thumbnail($s, $next);
            $s->fill(['progress' => $next, 'status' => $next === $s->page_count ? 'ready' : 'queued', 'error' => null])->save();

            return $s->status !== 'ready';
        }, 3);
        if ($more) {
            self::dispatch($this->sourceId);
        } elseif (config('dossiers.ai.enabled') && config('dossiers.ai.auto_analyze') && \Illuminate\Support\Facades\Schema::hasTable('dossier_ai_runs')) {
            $source = DossierSource::findOrFail($this->sourceId);
            if ($source->page_count <= 60) {
                app(\App\Services\Dossiers\Ai\DossierAiService::class)->request($source, $source->user_id, true);
            }
        }
    }

    public function failed(?\Throwable $e): void
    {
        $message = $e?->getMessage() ?? '';
        $safeError = str_starts_with($message, 'PDF hỏng') || str_starts_with($message, 'Không tạo được thumbnail') || str_starts_with($message, 'Checksum file nguồn')
            || str_starts_with($message, 'Thiếu/lỗi qpdf') || str_starts_with($message, 'qpdf không xác nhận') || str_starts_with($message, 'Bản xử lý khác')
            || str_starts_with($message, 'PDF có cảnh báo') || str_starts_with($message, 'PDF mã hóa') || str_starts_with($message, 'qpdf báo lỗi')
            || str_starts_with($message, 'qpdf không hoàn tất') || str_starts_with($message, 'Bản xử lý không') || str_starts_with($message, 'Không khởi động được')
            || str_starts_with($message, 'Xử lý PDF quá thời gian') || str_starts_with($message, 'Không đọc được cấu trúc trang')
            ? $message : 'Xử lý PDF thất bại. Kiểm tra PDF, cấu hình Poppler và log job; retry riêng file.';
        DossierSource::whereKey($this->sourceId)->where('status', '!=', 'ready')->update([
            'status' => 'failed', 'error' => $safeError,
        ]);
    }
}
