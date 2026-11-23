@if($pending->isNotEmpty())
<div class="scroll d-table machine-table" @if($pendingId) id="{{ $pendingId }}" @endif>
<table><thead><tr><th>Loại giấy</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
@foreach($pending as $paper)
<tr><td><strong>{{ $paper->data['title'] }}</strong><small>Trang {{ implode(', ', $paper->data['pages']) }}</small></td>
<td><span class="badge {{ ($paper->data['publication']??null)==='candidate'?'valid':'review' }}">
{{ ($paper->data['publication']??null)==='candidate' ? 'Có bản mới' : ($paper->data['subject']==='driver'?'Chưa gắn tài xế':'Cần đối chiếu') }}
</span></td>
<td>
@if(($paper->data['publication']??null)==='candidate')
@include('dossiers.machine-candidate')
@elseif($paper->data['subject']==='driver')
<button type="button" class="secondary" data-open-paper="driver-{{ $paper->id }}">Chọn tài xế</button>
<dialog class="machine-dialog" id="driver-{{ $paper->id }}" aria-labelledby="driver-title-{{ $paper->id }}">
<header><h3 id="driver-title-{{ $paper->id }}">Chọn tài xế · {{ $paper->data['title'] }}</h3><button type="button" class="secondary" data-close-paper aria-label="Đóng">×</button></header>
<form method="post" action="{{ route('dossiers.ai.publish',$paper->run_id) }}">@csrf
<input type="hidden" name="proposal_id" value="{{ $paper->id }}">
<label>Tài xế <select name="driver_id" required><option value="">Chọn đúng người</option>
@foreach($drivers as $history)<option value="{{ $history->driver_id }}">{{ $driverLabels[$history->driver_id] ?? $history->driver->name }}</option>@endforeach
</select></label>
@if($drivers->isEmpty())<p>Máy chưa có tài xế đang phân công.</p>@endif
<footer><button @disabled($drivers->isEmpty())>Lưu tài xế</button></footer></form></dialog>
@else
<a href="{{ route('dossiers.source',$paper->run->source_id) }}#proposal-{{ $paper->id }}">Mở nguồn và đối chiếu</a>
@endif
</td></tr>
@endforeach
</tbody></table></div>
@endif
