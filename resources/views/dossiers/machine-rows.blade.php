@php
 $missing = $rows->where('status','missing')->filter(fn($row)=>!$progress->contains('row_key',implode(':',[$row['subject'],$row['subject_id'],$row['type']->id])));
 $shown = $rows->reject(fn ($row,$key)=>$missing->has($key));
 $new = $progress->filter(fn ($row)=>str_starts_with($row['row_key'] ?? '',$subjectKey.':'))
    ->reject(fn($row)=>$shown->contains(fn($paper)=>implode(':',[$paper['subject'],$paper['subject_id'],$paper['type']->id])===$row['row_key']))->unique('row_key');
@endphp
<div class="scroll d-table machine-table"><table><thead><tr><th>Loại giấy</th><th>Hạn</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
@foreach($shown as $r)
@php
 $v=$r['version'];
 $paper=($byVersion??collect())->get($v?->id);
 $paperKey=implode(':',[$r['subject'],$r['subject_id'],$r['type']->id]);
 $transfer=$progress->firstWhere('row_key',$paperKey);
 $candidates = $papers->whereNull('version_id')->filter(fn ($p) => ($p->data['publication']??null)==='candidate' &&
     $p->data['type_id']===$r['type']->id && $p->data['subject']===$r['subject'] &&
     ($r['subject']!=='driver' || ($p->data['driver_id']??null)===$r['subject_id']));
 $editable=$v?->confirmed && !$v->archived && $r['status']!=='future';
 $tracked=in_array($r['type']->ai_key,\App\Services\Dossiers\Ai\DossierAiSchema::EXPIRY_TYPES,true);
@endphp
<tr @if($v) id="machine-paper-{{ $v->id }}" @endif>
<td><strong>{{ $r['type']->name }}</strong>@if($r['subject']==='assignment')<small>{{ $r['subject_name'] }}</small>@endif</td>
<td>{{ $tracked ? ($v?->expiry_value ?: ($v?->expiry_precision==='permanent' ? 'Không có hạn' : '—')) : '—' }}</td>
<td><span class="badge {{ $tracked && $v?->expiry_precision==='unknown' ? 'missing' : $r['status'] }}">{{ $tracked && $v?->expiry_precision==='unknown' ? 'Thiếu hạn' : $r['label'] }}</span>
@if($editable && $candidates->isNotEmpty())<button type="button" class="machine-new" data-open-paper="new-{{ $v->id }}">Có bản mới</button>@endif
<a data-paper-progress="{{ $paperKey }}" @if($transfer) href="{{ $transfer['url'] }}{{ $transfer['review'] ? '#ocr-review' : '' }}" @else hidden @endif>{{ $transfer['label'] ?? '' }}{{ ($transfer['total'] ?? 0) ? ' · '.$transfer['count'].'/'.$transfer['total'].' trang' : '' }}</a></td>
<td>@if($editable)
<div class="machine-actions">
@if($tracked && $v->expiry_precision==='unknown')
<button type="button" class="secondary" data-open-paper="expiry-{{ $v->id }}">Nhập hạn</button>
@if($paper)<form method="post" action="{{ route('dossiers.ai.information',$paper) }}">@csrf<button class="secondary">OCR lại hạn</button></form>@endif
@endif
<button type="button" class="secondary" data-open-paper="actions-{{ $v->id }}">Thao tác</button>
</div>
@include('dossiers.machine-paper-dialogs')
@endif</td></tr>
@endforeach
@foreach($new as $row)
@php($paperType=$editTypes->firstWhere('id',(int)str($row['row_key'])->afterLast(':')->value()))
<tr><td><strong>{{ $paperType?->name ?? $row['name'] }}</strong></td><td>—</td><td><a data-paper-progress="{{ $row['row_key'] }}" href="{{ $row['url'] }}{{ $row['review'] ? '#ocr-review' : '' }}">{{ $row['label'] }}</a></td><td><a href="{{ $row['url'] }}">Mở file</a></td></tr>
@endforeach
@if($shown->isEmpty() && $new->isEmpty())<tr><td colspan="4">Chưa có giấy tờ.</td></tr>@endif
</tbody></table></div>
@if($missing->isNotEmpty())<details><summary>{{ $missing->count() }} giấy bắt buộc còn thiếu</summary><ul>@foreach($missing as $item)<li>{{ $item['type']->name }}</li>@endforeach</ul></details>@endif
