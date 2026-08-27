<?php
namespace App\Exports;
use App\Models\MachineIntakeCase;
use App\Services\MachineSpecificationNormalizer;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
class MachineIntakeBchExport implements FromArray,WithHeadings,WithStyles,ShouldAutoSize
{
 public function __construct(private MachineIntakeCase $case){}
 public function headings():array{return ['STT','Mã máy','Tên Xe*','Biển số','Số khung','Số máy','Tên NCC','Dự án','Ngày dự kiến về','Ghi chú','Năm SX','BÀN GIAO VỀ'];}
 public function array():array{$n=app(MachineSpecificationNormalizer::class);return [[1,'',$n->displayType($this->case),$n->displayIdentity($this->case),$this->case->chassis_no,$this->case->engine_no,'Toàn Thắng',$this->case->project?->name,$this->case->handover_at?->format('d/m/Y'),'Chờ cấp mã',$this->case->manufacture_year,$this->case->company]];}
 public function styles(Worksheet $s):array{$s->freezePane('A2');$s->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');$s->getStyle('A1:L1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF2563EB');$s->getStyle('A1:L2')->getBorders()->getAllBorders()->setBorderStyle('thin');return [1=>['font'=>['bold'=>true]]];}
}
