<?php

namespace App\Exports;

use App\Models\ReconciliationPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class ReconciliationBchSheet implements FromArray, WithEvents, WithTitle, ShouldAutoSize
{
    private array $machineRanges = [];

    public function __construct(
        private readonly ReconciliationPeriod $period,
        private readonly Collection $rows,
        private readonly string $sheetTitle
    ) {
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function array(): array
    {
        $first = $this->rows->first();
        $month = $this->period->date_from->format('m/Y');
        $range = $this->period->date_from->format('d/m/Y').' - '.$this->period->date_to->format('d/m/Y');
        $projectNames = $this->rows->pluck('project.name')->filter()->unique()->implode(', ');
        $result = [
            ['BÁO CÁO TỔNG HỢP ĐỐI CHIẾU NHẬT TRÌNH THÁNG '.$month.' TỪ NGÀY '.$range.' - NCC TOÀN THẮNG'],
            ['DỰ ÁN: '.mb_strtoupper($projectNames).' - '.$this->sheetTitle],
            ['STT', 'Loại xe', 'Biển số xe', 'Ngày tháng', 'Định vị', '', '', 'Thời gian ghi nhận trên nhật trình', '', '', '', '', '', '', '', '', '', '', 'Vị trí thi công', 'Lỗi/vi phạm/sai khác/giải trình', 'BCH', 'KTC', 'Khóa dữ liệu', 'Nội dung công việc'],
            ['', '', '', '', 'Bắt đầu', 'Kết thúc', 'Tổng', 'Hành chính Ca sáng', '', 'Hành chính Ca chiều', '', 'Tăng ca trưa', '', 'Tăng ca chiều', '', 'Tăng ca tối', '', 'Tổng thời gian làm việc'],
            ['', '', '', '', '', '', '', 'Bắt đầu (trên NT)', 'Kết thúc (trên NT)', 'Bắt đầu (trên NT)', 'Kết thúc (trên NT)', 'Bắt đầu (trên NT)', 'Kết thúc (trên NT)', 'Bắt đầu (trên NT)', 'Kết thúc (trên NT)', 'Bắt đầu (trên NT)', 'Kết thúc (trên NT)'],
        ];

        foreach ($this->rows->groupBy('machine_id') as $machineRows) {
            $machine = $machineRows->first()->machine;
            $summaryRow = count($result) + 1;
            $firstDayRow = $summaryRow + 1;
            $lastDayRow = $firstDayRow + $this->period->date_from->diffInDays($this->period->date_to);
            $this->machineRanges[] = [$summaryRow, $firstDayRow, $lastDayRow];

            $result[] = [
                '',
                $machine?->machine_type,
                $machine?->asset_code,
                '', '', '', '=SUM(G'.$firstDayRow.':G'.$lastDayRow.')',
                '', '', '', '', '', '', '', '', '', '', '=SUM(R'.$firstDayRow.':R'.$lastDayRow.')',
                '', '', $first->commandCenter?->name,
            ];

            $rowsByDate = $machineRows->groupBy(fn ($row) => $row->work_date->format('Y-m-d'));
            $sequence = 1;
            for ($date = $this->period->date_from->copy(); $date->lte($this->period->date_to); $date->addDay()) {
                $dayRows = $rowsByDate->get($date->format('Y-m-d'), collect());
                $row = $dayRows->first();
                $active = $row !== null;
                $gpsMinutes = $active ? $this->minutesBetween($row->gps_check_in, $row->gps_check_out) : null;
                $logbookMinutes = $active ? $this->logbookMinutes($row) : null;

                $result[] = [
                    $sequence++,
                    $machine?->machine_type,
                    $machine?->asset_code,
                    $date->copy(),
                    $active ? $row->gps_check_in : null,
                    $active ? $row->gps_check_out : null,
                    $gpsMinutes === null ? null : $gpsMinutes / 1440,
                    $active ? $row->regular_morning_start : null,
                    $active ? $row->regular_morning_end : null,
                    $active ? $row->regular_afternoon_start : null,
                    $active ? $row->regular_afternoon_end : null,
                    $active ? $row->overtime_lunch_start : null,
                    $active ? $row->overtime_lunch_end : null,
                    $active ? $row->overtime_afternoon_start : null,
                    $active ? $row->overtime_afternoon_end : null,
                    $active ? $row->overtime_evening_start : null,
                    $active ? $row->overtime_evening_end : null,
                    $logbookMinutes === null ? null : $logbookMinutes / 1440,
                    $active ? $row->work_location : null,
                    $active ? $row->explanation : null,
                    $active ? $row->commandCenter?->name : null,
                    null,
                    $active ? implode('|', [$row->command_center_id, $machine?->asset_code, $date->format('Ymd'), $row->id]) : null,
                    $active ? $row->work_content : null,
                ];
            }
        }

        return $result;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $sheet->mergeCells('A1:X1');
                $sheet->mergeCells('A2:X2');
                $sheet->mergeCells('A3:A5');
                $sheet->mergeCells('B3:B5');
                $sheet->mergeCells('C3:C5');
                $sheet->mergeCells('D3:D5');
                $sheet->mergeCells('E3:G3');
                $sheet->mergeCells('H3:R3');
                $sheet->mergeCells('S3:S5');
                $sheet->mergeCells('T3:T5');
                $sheet->mergeCells('U3:U5');
                $sheet->mergeCells('V3:V5');
                $sheet->mergeCells('W3:W5');
                $sheet->mergeCells('X3:X5');
                $sheet->mergeCells('E4:E5');
                $sheet->mergeCells('F4:F5');
                $sheet->mergeCells('G4:G5');
                $sheet->mergeCells('H4:I4');
                $sheet->mergeCells('J4:K4');
                $sheet->mergeCells('L4:M4');
                $sheet->mergeCells('N4:O4');
                $sheet->mergeCells('P4:Q4');
                $sheet->mergeCells('R4:R5');

                $sheet->getStyle('A1:X2')->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle('A1:X2')->getFont()->getColor()->setRGB('000000');
                $sheet->getStyle('A1:X2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('A1:X2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension(1)->setRowHeight(24);
                $sheet->getRowDimension(2)->setRowHeight(22);
                $sheet->getRowDimension(3)->setRowHeight(25);
                $sheet->getRowDimension(4)->setRowHeight(34);
                $sheet->getRowDimension(5)->setRowHeight(42);
                $sheet->getStyle('A3:X5')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                ]);
                $sheet->getStyle('A3:X'.$lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('808080'));
                $sheet->getStyle('A6:X'.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
                $sheet->getStyle('D6:D'.$lastRow)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                $sheet->getStyle('E6:F'.$lastRow)->getNumberFormat()->setFormatCode('hh:mm');
                $sheet->getStyle('H6:Q'.$lastRow)->getNumberFormat()->setFormatCode('hh:mm');
                $sheet->getStyle('G6:G'.$lastRow)->getNumberFormat()->setFormatCode('[h]:mm');
                $sheet->getStyle('R6:R'.$lastRow)->getNumberFormat()->setFormatCode('[h]:mm');

                foreach ($this->machineRanges as [$summaryRow, $firstDayRow, $lastDayRow]) {
                    $sheet->getStyle('A'.$summaryRow.':X'.$summaryRow)->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EAF7']],
                    ]);
                    $sheet->getRowDimension($summaryRow)->setRowHeight(30);
                    for ($row = $firstDayRow; $row <= $lastDayRow; $row++) {
                        $sheet->getRowDimension($row)->setRowHeight(22);
                    }
                }

                foreach (['A'=>6,'B'=>26,'C'=>15,'D'=>13,'E'=>11,'F'=>11,'G'=>12,'H'=>11,'I'=>11,'J'=>11,'K'=>11,'L'=>11,'M'=>11,'N'=>11,'O'=>11,'P'=>11,'Q'=>11,'R'=>12,'S'=>22,'T'=>34,'U'=>16,'V'=>12,'W'=>20,'X'=>32] as $column => $width) {
                    $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth($width);
                }
                $sheet->freezePane('A6');
                $sheet->setAutoFilter('A5:X'.$lastRow);
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 5);
                $sheet->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.2)->setRight(0.2);
                $sheet->getPageSetup()->setPrintArea('A1:V'.$lastRow);
                $sheet->getColumnDimension('W')->setVisible(false);
                $sheet->getColumnDimension('X')->setVisible(false);
            },
        ];
    }

    private function minutesBetween(?string $start, ?string $end): ?int
    {
        if (!$start || !$end) {
            return null;
        }

        $from = Carbon::createFromFormat('H:i:s', $start);
        $to = Carbon::createFromFormat('H:i:s', $end);

        return (int) $from->diffInMinutes($to);
    }

    private function logbookMinutes($row): ?int
    {
        $parts = array_filter([
            $row->regular_minutes,
            $row->lunch_minutes,
            $row->ot_afternoon_minutes,
            $row->ot_evening_minutes,
        ], fn ($value) => $value !== null);

        return $parts === [] ? null : array_sum($parts);
    }
}
