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
        $issues = [];
        $report = app(DossierReport::class);
        $machines = Machine::orderBy('asset_code')->get();
        $report->warm($machines);
        foreach ($machines as $m) {
            $rows = $report->rows($m);
            foreach ($report->pairs($m) as $pair) {
                $assignment = $pair['assignment'];
                $summary[] = [$m->asset_code, $m->machine_type, $assignment?->commandCenter?->name, $assignment?->project?->name,
                    $pair['driver']?->name ?? 'Chưa gán tài', ...array_map(fn ($s) => $pair['counts'][$s] ?? 0, array_keys(DossierStatus::LABELS)),
                    $pair['checked_on'], $pair['rows']->map(fn ($row) => $row['type']->name.' — '.$row['label'])->implode(' | ')];
            }
            foreach ($rows as $r) {
                $v = $r['version'];
                $detail = [$m->asset_code, $r['subject'], $r['subject_name'], $r['type']->name,
                    $r['record']?->instance, $v?->id, $r['type']->ai_key === 'identity' && $v?->number ? '••••' : $v?->number, $v?->issuer, $v?->issued_on, $v?->effective_at,
                    $v?->certified_on, $v?->expiry_precision, $v?->expiry_value, $r['days'], $r['label'],
                    $v?->provenance['expiry_origin'] ?? 'manual', $v?->health_signed_on, $v?->renewal_on, $r['checked_on'], $r['checked_by']];
                $details[] = $detail;
                if (in_array($r['status'], ['missing', 'review', 'expired'], true)) {
                    $issues[] = $detail;
                }
            }
        }

        $headings = ['Mã máy', 'Chủ thể', 'Tên chủ thể', 'Loại giấy', 'Phạm vi phụ', 'Phiên bản', 'Số giấy', 'Đơn vị cấp', 'Ngày cấp', 'Hiệu lực', 'Chứng thực', 'Độ chính xác hạn', 'Hạn', 'Ngày còn lại', 'Trạng thái', 'Nguồn hạn đã duyệt', 'Ngày ký kết luận sức khỏe', 'Ngày cần làm lại riêng', 'Ngày kiểm tra', 'Người kiểm tra (ID)'];

        return [new DossierWorksheet('Tổng hợp', ['Mã máy', 'Tên / loại', 'BCH', 'Công trình', 'Tài xế hiện tại', ...array_values(DossierStatus::LABELS), 'Kiểm tra gần nhất', 'Giấy và tình trạng'], $summary),
            new DossierWorksheet('Chi tiết', $headings, $details), new DossierWorksheet('Cần kiểm tra', $headings, $issues)];

    }
}
