<?php
namespace App\Services;
class MachineSpecificationNormalizer
{
 public function normalize(array $data): array
 {
  $raw=mb_strtolower(trim(($data['machine_type']??'').' '.($data['model_name']??'')));
  $axles=isset($data['vehicle_axles'])?(int)$data['vehicle_axles']:null;
  if(!$axles && preg_match('/(?:xe|ô tô|oto|ben).*?([234])\s*(?:chân|chan)/u',$raw,$m))$axles=(int)$m[1];
  $type=match(true){str_contains($raw,'bánh lốp')||str_contains($raw,'banh lop')||str_contains($raw,'xúc lốp')=>'Máy xúc bánh lốp',str_contains($raw,'bánh xích')||str_contains($raw,'banh xich')||str_contains($raw,'xúc xích')||str_contains($raw,'đào bánh xích')=>'Máy xúc bánh xích',str_contains($raw,'lu rung')||str_contains($raw,'máy lu')=>'Lu rung 12–14 tấn',$axles!==null||str_contains($raw,'xe ben')||str_contains($raw,'ô tô')=>'Xe ô tô',default=>trim((string)($data['machine_type']??''))};
  $capacity=isset($data['capacity_class'])?(int)$data['capacity_class']:null;
  if(str_starts_with($type,'Máy xúc')&&!in_array($capacity,[55,140,200,300],true)){$number=$this->modelNumber((string)($data['model_name']??$data['machine_type']??''));if($number)$capacity=$this->nearest($number);}
  return array_merge($data,['machine_type'=>$type,'capacity_class'=>$capacity,'vehicle_axles'=>$axles,'brand'=>$this->brand($data)]);
 }
 public function displayType(array|object $data): string { $v=$this->values($data);$type=$v['machine_type']??'';return $type==='Xe ô tô'&&($v['vehicle_axles']??null)?"Xe ô tô {$v['vehicle_axles']} chân":(str_starts_with($type,'Máy xúc')&&($v['capacity_class']??null)?"{$type} {$v['capacity_class']}":$type); }
 public function displayIdentity(array|object $data): string {$v=$this->values($data);return trim(implode(' · ',array_filter([trim(implode(' ',array_filter([$v['brand']??null,$v['model_name']??null]))),$v['plate_no']??null])));}
 private function nearest(int $n): int {return collect([55,140,200,300])->sortBy(fn($x)=>abs($x-$n))->first();}
 private function modelNumber(string $v): ?int {preg_match_all('/\d{2,3}/',$v,$m);foreach($m[0]??[] as $n){$n=(int)$n;if($n>=45&&$n<=380)return $n;}return null;}
 private function brand(array $data): ?string {if(!empty($data['brand']))return trim($data['brand']);$text=($data['model_name']??'').' '.($data['machine_type']??'');foreach(['Doosan','Komatsu','Volvo','Hitachi','Hyundai','HOWO','Sakai','Hamm','Kobelco'] as $b)if(stripos($text,$b)!==false)return $b;return null;}
 private function values(array|object $data):array{return is_array($data)?$data:(method_exists($data,'getAttributes')?$data->getAttributes():get_object_vars($data));}
}
