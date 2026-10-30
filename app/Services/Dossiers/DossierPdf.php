<?php

namespace App\Services\Dossiers;

use App\Models\DossierSource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Symfony\Component\Process\Process;

class DossierPdf
{
    public function prepare(DossierSource $source): int
    {
        $disk = Storage::disk('dossiers');
        $original = $disk->path($source->path);
        if (! hash_equals($source->sha256, hash_file('sha256', $original))) {
            throw new RuntimeException('Checksum file nguồn không khớp.');
        }
        if ($source->processed_path) {
            if (! hash_equals($source->processed_sha256, hash_file('sha256', $disk->path($source->processed_path)))) {
                throw new RuntimeException('Checksum bản xử lý không khớp.');
            }

            return $this->inspect($disk->path($source->processed_path));
        }
        try {
            return $this->inspect($original);
        } catch (RuntimeException $e) {
            $cause = $e->getPrevious();
            Log::warning('dossier.pdf.source_parser', ['source_id' => $source->id, 'exception' => $cause ? get_class($cause) : get_class($e), 'code' => $cause?->getCode()]);
            if ($cause instanceof CrossReferenceException && $cause->getCode() === CrossReferenceException::ENCRYPTED) {
                throw new RuntimeException('PDF mã hóa; hệ thống không tự giải mã. File nguồn vẫn được giữ.', 0, $e);
            }
            // Normalize unsupported compressed cross references. Recovery of a known
            // warning still requires independent validation; never decrypt sources.
            if (! $cause instanceof CrossReferenceException || $cause->getCode() !== CrossReferenceException::COMPRESSED_XREF) {
                throw $e;
            }
        }
        $relative = 'processed/'.$source->id.'/'.$source->sha256.'-qpdf.pdf';
        $disk->makeDirectory(dirname($relative));
        $temp = $relative.'.'.\Illuminate\Support\Str::uuid().'.tmp';
        $trial = $temp.'.trial.pdf';
        try {
            $before = $this->geometry($original, $source->id);
            $version = $this->runTool([config('dossiers.qpdf'), '--version'], 10, $source->id, 'qpdf_version');
            if (! $version->isSuccessful() || ! str_contains($version->getOutput(), 'qpdf version')) {
                throw new RuntimeException('Không khởi động được qpdf hợp lệ. Kiểm tra đường dẫn/quyền chạy; file nguồn vẫn được giữ.');
            }
            $args = [config('dossiers.qpdf'), '--object-streams=disable', '--stream-data=preserve', '--deterministic-id', $original, $disk->path($temp)];
            $process = $this->runTool($args, 120, $source->id, 'normalize');
            $exit = $process->getExitCode();
            if ($exit === 3 && ! $this->isRecoverableXrefWarning($process->getErrorOutput())) {
                throw new RuntimeException('PDF có cảnh báo cấu trúc chưa được xác minh an toàn. File nguồn được giữ; xem diagnostics để xử lý.');
            }
            if (! in_array($exit, [0, 3], true)) {
                throw new RuntimeException($exit === 2 ? 'qpdf báo lỗi cấu trúc PDF (mã 2), không phải cảnh báo. File nguồn vẫn được giữ.' : 'qpdf không hoàn tất được xử lý. Kiểm tra chương trình/quyền chạy và diagnostics.');
            }
            // Exit3 is only a candidate for recovery, never proof of a valid output.
            $check = $this->runTool([config('dossiers.qpdf'), '--check', $disk->path($temp)], 60, $source->id, 'check_processed');
            if ($check->getExitCode() !== 0) {
                throw new RuntimeException('Bản xử lý không vượt kiểm tra cú pháp/stream của qpdf; chưa chấp nhận hồ sơ.');
            }
            $count = $this->inspect($disk->path($temp));
            $after = $this->geometry($disk->path($temp), $source->id);
            if ($before !== $after) {
                throw new RuntimeException('Bản xử lý khác số trang/kích thước/xoay so với nguồn; dừng để kiểm tra.');
            }
            $this->merge([['source_path' => $source->path, 'source_sha256' => $source->sha256,
                'processed_path' => $temp, 'processed_sha256' => hash_file('sha256', $disk->path($temp)),
                'pages' => array_map(fn ($p) => ['page' => $p, 'rotation' => 0], range(1, $count))]], $trial);
            $trialPages = $this->inspect($disk->path($trial));
            if ($trialPages !== $count) {
                throw new RuntimeException('Bản xử lý không xuất/mở lại đủ trang PDF; chưa chấp nhận hồ sơ.');
            }
            $disk->move($temp, $relative);
            $source->fill(['processed_path' => $relative, 'processed_sha256' => hash_file('sha256', $disk->path($relative)),
                'processing' => ['method' => 'qpdf-object-streams-disable', 'version' => trim($version->getOutput()),
                    'options' => ['object-streams=disable', 'stream-data=preserve', 'deterministic-id'],
                    'source_sha256' => $source->sha256, 'geometry' => $before,
                    'normalize_exit_code' => $exit, 'warnings' => $exit === 3 ? ['missing_xref_self_entry'] : [],
                    'validation' => ['qpdf_check_exit_code' => 0, 'geometry_match' => true, 'parser_pages' => $count, 'trial_export_pages' => $trialPages],
                    'rasterized' => false, 'downsampled' => false, 'processed_at' => now()->toIso8601String(),
                    'visual_acceptance' => 'Manual document confirmation required']])->save();

            return $count;
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface $e) {
            throw new RuntimeException('Thiếu/lỗi qpdf hoặc pdfinfo. Cấu hình DOSSIERS_QPDF/DOSSIERS_PDFINFO rồi retry; không cần người dùng đổi PDF.', 0, $e);
        } finally {
            $disk->delete($temp);
            $disk->delete($trial);
        }
    }

    protected function runTool(array $args, int $timeout, int $sourceId, string $stage): Process
    {
        $p = new Process($args, null, ['LC_ALL' => 'C']);
        $p->setTimeout($timeout);
        $start = microtime(true);
        try {
            $p->run();
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface $e) {
            Log::error('dossier.pdf.tool', ['source_id' => $sourceId, 'stage' => $stage, 'arguments' => $args,
                'timeout_seconds' => $timeout, 'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
                'exit_code' => $p->getExitCode(), 'timed_out' => $e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException,
                'exception' => get_class($e), 'stdout' => $p->isStarted() ? $this->diagnosticOutput($p, $stage) : '',
                'stderr' => $p->isStarted() ? substr($p->getErrorOutput(), 0, 16384) : '']);
            throw new RuntimeException($e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException
                ? 'Xử lý PDF quá thời gian ở bước '.$stage.'. File nguồn vẫn được giữ; kiểm tra diagnostics trước khi retry.'
                : 'Không khởi động được công cụ PDF ở bước '.$stage.'. Kiểm tra đường dẫn/quyền chạy.', 0, $e);
        }
        Log::info('dossier.pdf.tool', ['source_id' => $sourceId, 'stage' => $stage, 'arguments' => $args,
            'timeout_seconds' => $timeout, 'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
            'exit_code' => $p->getExitCode(), 'timed_out' => false,
            'stdout' => $this->diagnosticOutput($p, $stage), 'stderr' => substr($p->getErrorOutput(), 0, 16384)]);

        return $p;
    }

    private function diagnosticOutput(Process $process, string $stage): string
    {
        $output = $process->getOutput();
        if ($stage === 'geometry') {
            // pdfinfo can include personal Title/Author metadata; log geometry only.
            $output = implode("\n", array_filter(preg_split('/\R/', $output), fn ($line) => preg_match('/^(?:Pages:|Encrypted:|PDF version:|Page\s+\d+\s+(?:size|rot|MediaBox|CropBox|BleedBox|TrimBox|ArtBox):)/', $line)));
        }

        return substr($output, 0, 16384);
    }

    private function isRecoverableXrefWarning(string $stderr): bool
    {
        $found = false;
        foreach (preg_split('/\R/', trim($stderr)) as $line) {
            if (str_starts_with($line, 'WARNING: ') && str_ends_with($line, 'xref entry for the xref stream itself is missing - a common error handled correctly by qpdf and most other applications')) {
                $found = true;
            } elseif (trim($line) !== '' && $line !== 'qpdf: operation succeeded with warnings; resulting file may have some problems') {
                return false;
            }
        }

        return $found;
    }

    private function geometry(string $path, int $sourceId): array
    {
        $process = $this->runTool([config('dossiers.pdfinfo'), '-box', '-f', '1', '-l', (string) config('dossiers.max_pages'), $path], 30, $sourceId, 'geometry');
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Không đọc được cấu trúc trang từ pdfinfo. File nguồn có thể mã hóa/hỏng; xem diagnostics.');
        }
        $lines = preg_split('/\R/', $process->getOutput());
        $geometry = [];
        foreach ($lines as $line) {
            if (preg_match('/^Encrypted:\s+yes/i', $line)) {
                throw new RuntimeException('PDF mã hóa không được tự giải mã.');
            }
            if (preg_match('/^(?:Pages:|Page\s+\d+\s+(?:size|rot|MediaBox|CropBox|BleedBox|TrimBox|ArtBox):)/', $line)) {
                $geometry[] = preg_replace('/\s+/', ' ', trim($line));
            }
        }
        if (count($geometry) < 2) {
            throw new RuntimeException('Không đọc được cấu trúc trang từ pdfinfo.');
        }

        return $geometry;
    }

    public function inspect(string $path): int
    {
        try {
            $pdf = new Fpdi;
            $count = $pdf->setSourceFile($path);
            if ($count < 1 || $count > config('dossiers.max_pages')) {
                throw new RuntimeException('Số trang ngoài giới hạn cho phép.');
            }
            for ($i = 1; $i <= $count; $i++) {
                $pdf->importPage($i);
            }

            return $count;
        } catch (\Throwable $e) {
            throw new RuntimeException('PDF hỏng, mã hóa hoặc chưa được chuẩn hóa tương thích. Kiểm tra lỗi xử lý và retry file này.', 0, $e);
        }
    }

    public function thumbnail(DossierSource $source, int $page): void
    {
        $disk = Storage::disk('dossiers');
        $prefix = $disk->path("thumbnails/{$source->id}/{$page}");
        $disk->makeDirectory("thumbnails/{$source->id}");
        $p = new Process([config('dossiers.pdftoppm'), '-f', (string) $page, '-l', (string) $page,
            '-singlefile', '-scale-to', '1000', '-png', $disk->path($source->path), $prefix]);
        $p->setTimeout(60);
        $p->run();
        if (! $p->isSuccessful() || ! is_file($prefix.'.png')) {
            throw new RuntimeException('Không tạo được thumbnail. Kiểm tra DOSSIERS_PDFTOPPM/Poppler và retry file này.');
        }
    }

    /** $items is an ordered immutable manifest; rotations are applied only to the derivative. */
    public function merge(array $items, string $destination): void
    {
        $disk = Storage::disk('dossiers');
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);
        foreach ($items as $item) {
            $path = $disk->path($item['source_path']);
            if (! hash_equals($item['source_sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('Checksum nguồn không khớp; dừng xuất để bảo toàn hồ sơ.');
            }
            if ($item['processed_path'] ?? null) {
                $path = $disk->path($item['processed_path']);
                if (! hash_equals($item['processed_sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('Checksum bản xử lý không khớp manifest.');
                }
            }
            $pdf->setSourceFile($path);
            foreach ($item['pages'] as $page) {
                $tpl = $pdf->importPage($page['page']);
                $size = $pdf->getTemplateSize($tpl);
                // FPDF page rotation changes the derivative's Rotate entry, retaining original geometry.
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']], $page['rotation']);
                $pdf->useTemplate($tpl);
            }
        }
        $disk->makeDirectory(dirname($destination));
        $pdf->Output('F', $disk->path($destination));
    }
}
