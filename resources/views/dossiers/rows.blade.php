@php
 $missing = $rows->where('status','missing')->filter(fn($row)=>!$progress->contains('row_key',implode(':',[$row['subject'],$row['subject_id'],$row['type']->id])));
 $shown = $rows->reject(fn ($row,$key)=>$missing->has($key));
 $new = $progress->filter(fn ($row)=>str_starts_with($row['row_key'] ?? '',$subjectKey.':'))
    ->reject(fn($row)=>$shown->contains(fn($paper)=>implode(':',[$paper['subject'],$paper['subject_id'],$paper['type']->id])===$row['row_key']))->unique('row_key');
@endphp
<div class="scroll d-table"><table><thead><tr><th>Loại giấy</th><th>Hạn</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
@foreach($shown as $r)
@php
 $v=$r['version'];
 $paperKey=implode(':',[$r['subject'],$r['subject_id'],$r['type']->id]);
 $transfer=$progress->firstWhere('row_key',$paperKey);
 $tracked=in_array($r['type']->ai_key,\App\Services\Dossiers\Ai\DossierAiSchema::EXPIRY_TYPES,true);
@endphp
<tr><td><strong>{{ $r['type']->name }}</strong>
@if($r['subject']==='assignment')<small>{{ $r['subject_name'] }}</small>@endif
@if($v)<small><a href="{{ route('dossiers.source',$v->source_id) }}">Phiên bản #{{ $v->id }}</a></small>@endif</td>
<td>{{ $tracked ? ($v?->expiry_value ?: ($v?->expiry_precision==='permanent' ? 'Không có hạn' : '—')) : '—' }}</td>
<td><span class="badge {{ $r['status'] }}">{{ $r['label'] }}</span>
<a data-paper-progress="{{ $paperKey }}" @if($transfer) href="{{ $transfer['url'] }}{{ $transfer['review'] ? '#ocr-review' : '' }}" @else hidden @endif>{{ $transfer['label'] ?? '' }}{{ ($transfer['total'] ?? 0) ? ' · '.$transfer['count'].'/'.$transfer['total'].' trang' : '' }}</a></td>
<td>@if($v?->confirmed && !$v->archived && $r['status']!=='future')
<div class="d-actions">
<details><summary>Sửa</summary>
<form method="post" action="{{ route('dossiers.versions.correct',$v) }}" data-dossier-expiry>@csrf
<input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}">
<label>Loại giấy <select name="type_id">@foreach($editTypes as $type)
@if(in_array($group,$type->groups,true))
<option value="{{ $type->id }}" data-tracked="{{ (int) in_array($type->ai_key,\App\Services\Dossiers\Ai\DossierAiSchema::EXPIRY_TYPES,true) }}" @selected($type->id===$r['type']->id)>{{ $type->name }}</option>
@endif
@endforeach</select></label>
<label>Trang theo thứ tự xuất <input name="page_order" value="{{ implode(', ', array_column($v->pages,'page')) }}" required></label>
<a href="{{ route('dossiers.file',[$v->source_id,'source']) }}" target="_blank" rel="noopener">Xem PDF gốc để đối chiếu số trang</a>
<fieldset data-expiry-fields @if(!$tracked) hidden disabled @endif>
<label>Trạng thái hạn <select name="expiry_precision">@foreach(['unknown'=>'Chưa đọc được hạn','day'=>'Có hạn theo ngày','minute'=>'Có hạn tới giờ/phút','month'=>'Có hạn theo tháng','permanent'=>'Không có hạn'] as $key=>$label)<option value="{{ $key }}" @selected($v->expiry_precision===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Hạn trên giấy <input name="expiry_value" value="{{ $v->expiry_value }}" placeholder="YYYY-MM-DD"></label>
<label>Quy tắc tháng <select name="month_rule"><option value="">Chưa xác nhận</option><option value="end_of_month" @selected($v->month_rule==='end_of_month')>Tính hết ngày cuối tháng</option></select></label>
</fieldset>
<label>Lý do <input name="reason" required maxlength="2000"></label><button>Lưu sửa đổi</button>
</form>
</details>
<a href="{{ route('dossiers.versions.replace',$v) }}">Thay file</a>
<details><summary>Xóa</summary><form method="post" action="{{ route('dossiers.versions.archive',$v) }}">@csrf
<input type="hidden" name="archived" value="1"><label>Lý do <input name="reason" required maxlength="2000"></label><button class="secondary">Xác nhận xóa giấy này</button>
</form></details>
</div>
@endif</td></tr>
@endforeach
@foreach($new as $row)
@php($paperType=$editTypes->firstWhere('id',(int)str($row['row_key'])->afterLast(':')->value()))
<tr><td><strong>{{ $paperType?->name ?? $row['name'] }}</strong></td><td>—</td><td><a data-paper-progress="{{ $row['row_key'] }}" href="{{ $row['url'] }}{{ $row['review'] ? '#ocr-review' : '' }}">{{ $row['label'] }}</a></td><td><a href="{{ $row['url'] }}">Mở file</a></td></tr>
@endforeach
@if($shown->isEmpty() && $new->isEmpty())<tr><td colspan="4">Chưa có giấy tờ.</td></tr>@endif
</tbody></table></div>
@if($missing->isNotEmpty())<details><summary>{{ $missing->count() }} giấy bắt buộc còn thiếu</summary><ul>@foreach($missing as $item)<li>{{ $item['type']->name }}</li>@endforeach</ul></details>@endif
