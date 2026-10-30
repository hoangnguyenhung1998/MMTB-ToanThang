<?php

namespace App\Exports;

use App\Models\Machine;
use App\Services\Dossiers\DossierReport;
use App\Services\Dossiers\DossierStatus;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class DossierWorkbook implements WithMultipleSheets
{
    public function sheets(): array
    {
        $summary = [];
        $details = [];
        $report = app(DossierReport::class);
        $machines = Machine::orderBy('asset_code')->get();
        $report->warm($machines);
        foreach ($machines as $m) {
            $rows = $report->rows($m);
            $counts = $report->summary($rows);
            $assignment = $report->assignments($m)->first();
            $summary[] = [$m->asset_code, $m->machine_type, $assignment?->commandCenter?->name, $assignment?->project?->name,
                $report->drivers($m)->pluck('driver.name')->implode(', '),
                ...array_map(fn ($s) => $counts[$s] ?? 0, array_keys(DossierStatus::LABELS))];
            foreach ($rows as $r) {
                $v = $r['version'];
                $details[] = [$m->asset_code, $r['subject'], $r['subject_name'], $r['type']->name,
                    $r['record']?->instance, $v?->id, $v?->number, $v?->issuer, $v?->issued_on, $v?->effective_at,
                    $v?->certified_on, $v?->expiry_precision, $v?->expiry_value, $r['days'], $r['label'],
                    $v?->provenance['expiry_origin'] ?? 'manual', $v?->health_signed_on, $v?->renewal_on];
            }
        }

        return [new DossierWorksheet('Tổng hợp', ['Mã máy', 'Tên / loại', 'BCH', 'Công trình', 'Tài xế hiện tại', ...array_values(DossierStatus::LABELS)], $summary),
            new DossierWorksheet('Chi tiết', ['Mã máy', 'Chủ thể', 'Tên chủ thể', 'Loại giấy', 'Phạm vi phụ', 'Phiên bản', 'Số giấy', 'Đơn vị cấp', 'Ngày cấp', 'Hiệu lực', 'Chứng thực', 'Độ chính xác hạn', 'Hạn', 'Ngày còn lại', 'Trạng thái', 'Nguồn hạn đã duyệt', 'Ngày ký kết luận sức khỏe', 'Ngày cần làm lại riêng'], $details)];
    }
}
