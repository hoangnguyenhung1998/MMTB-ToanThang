<?php

namespace App\Exports;

use App\Models\ReconciliationPeriod;
use App\Services\Reconciliation\ReconciliationCalculator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReconciliationRowsExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithColumnFormatting, WithEvents
{
    private int $index = 0;

    public function __construct(
        private ReconciliationPeriod $period,
        private ReconciliationCalculator $calculator,
    ) {
    }

    public function collection(): Collection
    {
        return $this->period->rows()
            ->with(['machine', 'project', 'commandCenter', 'driver', 'reviewer', 'confirmer'])
            ->orderBy('work_date')
            ->orderBy('machine_id')
            ->get();
    }

    public function headings(): array
    {
        return [
            'STT',
            'Ngày',
            'Mã máy',
            'Tên thiết bị',
            'Dự án',
            'BCH',
            'Tài xế',
            'Vị trí thi công',
            'Nội dung công việc',
            'Giờ OCR bắt đầu',
            'Giờ OCR kết thúc',
            'Giờ GPS bắt đầu',
            'Giờ GPS kết thúc',
            'Giờ xác nhận',
            'Giờ hành chính',
            'OT chiều',
            'OT tối',
            'Tổng tăng ca',
            'Chênh lệch phút',
            'Mức chênh lệch',
            'Trạng thái dòng',
            'Loại biến động',
            'Giải trình',
            'Ghi chú',
            'Người duyệt',
            'Người xác nhận',
        ];
    }

    public function map($row): array
    {
        $this->index++;
        $calculation = $this->calculate($row);

        return [
            $this->index,
            optional($row->work_date)->format('d/m/Y') ?: $row->work_date,
            $this->text($row->machine->asset_code ?? $row->machine->code ?? $row->machine_code ?? ''),
            $this->text($row->machine->name ?? $row->machine_name ?? ''),
            $this->text($row->project->name ?? $row->project_name ?? ''),
            $this->text($row->commandCenter->name ?? $row->command_center_name ?? ''),
            $this->text($row->driver->name ?? $row->driver_name ?? ''),
            $this->text($row->location ?? ''),
            $this->text($row->work_content ?? ''),
            $row->ocr_start_time ?? $row->ocr_start_at ?? '',
            $row->ocr_end_time ?? $row->ocr_end_at ?? '',
            $row->gps_start_time ?? $row->gps_start_at ?? '',
            $row->gps_end_time ?? $row->gps_end_at ?? '',
            $row->confirmed_work_minutes ?? $row->confirmed_hours ?? '',
            $row->regular_minutes ?? $row->regular_hours ?? $row->ocr_regular_hours ?? '',
            $row->overtime_afternoon_minutes ?? $row->overtime_afternoon_hours ?? '',
            $row->overtime_night_minutes ?? $row->overtime_night_hours ?? '',
            $row->overtime_minutes ?? $row->overtime_hours ?? $row->ocr_overtime_hours ?? '',
            $calculation['difference_minutes'] ?? $row->difference_minutes ?? '',
            $calculation['variance'] ?? $calculation['variance_level'] ?? $row->variance_status ?? '',
            $row->status ?? '',
            $row->change_type ?? '',
            $this->text($row->review_note ?? $row->explanation ?? ''),
            $this->text($row->note ?? ''),
            $this->text($row->reviewer->name ?? ''),
            $this->text($row->confirmer->name ?? ''),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->freezePane('A2');
                $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                $sheet->getStyle('A:Z')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
                $sheet->getStyle('H:X')->getAlignment()->setWrapText(true);
            },
        ];
    }

    private function calculate($row): array
    {
        if (method_exists($this->calculator, 'calculate')) {
            return (array) $this->calculator->calculate($row);
        }

        if (method_exists($this->calculator, 'forRow')) {
            return (array) $this->calculator->forRow($row);
        }

        return [];
    }

    private function text(mixed $value): string
    {
        $text = trim((string) $value);

        return preg_match('/^[=+\-@]/', $text) ? "'".$text : $text;
    }
}
