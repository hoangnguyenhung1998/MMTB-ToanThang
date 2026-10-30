<h2>Lịch sử phiên bản</h2>
@foreach($records as $record)<details class="box"><summary>{{ $record->type->name }} / {{ $record->instance }} — #{{ $record->id }}</summary>
@php($current=app(\App\Services\Dossiers\DossierStatus::class)->current($record))
@foreach($record->versions->sortByDesc('effective_at') as $v)
<p><a href="{{ route('dossiers.source',$v->source_id) }}">Phiên bản #{{ $v->id }}</a> · {{ $v->effective_at }} · {{ $v->expiry_value }} · {{ $v->reason }} · {{ !$v->confirmed ? 'Nháp' : ($current?->id===$v->id ? 'Hiện hành' : ($v->effective_at > app(\App\Services\Dossiers\DossierStatus::class)->now()->format('Y-m-d H:i:s') ? 'Chưa có hiệu lực' : 'Đã thay thế')) }}</p>
<form method="post" action="{{ route('dossiers.versions.archive',$v) }}">@csrf<input type="hidden" name="archived" value="{{ $v->archived ? 0 : 1 }}"><label>Lý do <input name="reason" required maxlength="2000"></label><button>{{ $v->archived ? 'Khôi phục' : 'Lưu trữ' }} #{{ $v->id }}</button></form>
@endforeach</details>@endforeach
