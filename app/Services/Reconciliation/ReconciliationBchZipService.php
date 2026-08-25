<?php

namespace App\Services\Reconciliation;

use App\Exports\ReconciliationBchWorkbookExport;
use App\Models\ReconciliationPeriod;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use ZipArchive;

class ReconciliationBchZipService
{
    public function create(ReconciliationPeriod $period, array $filters = []): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Máy chủ chưa bật PHP ZipArchive; vẫn có thể xuất workbook Excel tổng hợp.');
        }

        $commandCenters = $period->rows()
            ->with('commandCenter:id,name')
            ->whereNotNull('command_center_id')
            ->when($filters['machine_id'] ?? null, fn ($query, $id) => $query->where('machine_id', $id))
            ->when($filters['project_id'] ?? null, fn ($query, $id) => $query->where('project_id', $id))
            ->when($filters['command_center_id'] ?? null, fn ($query, $id) => $query->where('command_center_id', $id))
            ->get()
            ->pluck('commandCenter')
            ->filter()
            ->unique('id')
            ->sortBy('name');

        $zipPath = tempnam(sys_get_temp_dir(), 'mmtb-bch-');
        if ($zipPath === false) {
            throw new RuntimeException('Không thể tạo tệp ZIP tạm thời.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Không thể mở tệp ZIP để xuất BCH.');
        }

        foreach ($commandCenters as $commandCenter) {
            $bchFilters = [...$filters, 'command_center_id' => $commandCenter->id];
            $content = Excel::raw(
                new ReconciliationBchWorkbookExport($period, $bchFilters),
                ExcelFormat::XLSX
            );
            $zip->addFromString(
                $this->safeFilename($commandCenter->name).'-'.$period->date_from->format('Y-m').'.xlsx',
                $content
            );
        }

        $zip->close();

        return $zipPath;
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[<>:"\/\\|?*]+/u', '-', trim($name));

        return trim($name, '. -') ?: 'BCH';
    }
}
