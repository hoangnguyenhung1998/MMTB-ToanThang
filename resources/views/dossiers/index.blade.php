@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
@include('dossiers.home-tabs',['activeTab'=>$batches ? 'batches' : 'machines'])
@if($batches)
<div class="scroll d-table"><table><thead><tr><th>Đợt nhập</th><th>Số file</th><th>OCR tự động</th><th>Thao tác</th></tr></thead><tbody>
@forelse($batches as $batch)
<tr><td>{{ $batch->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}</td><td>{{ $batch->sources_count }}</td><td>{{ ($batch->auto_analyze ?? config('dossiers.ai.auto_analyze')) ? 'Bật' : 'Tắt' }}</td><td><a href="{{ route('dossiers.batch',$batch) }}">Mở đợt nhập</a></td></tr>
@empty<tr><td colspan="4">Chưa có đợt nhập PDF.</td></tr>@endforelse
</tbody></table></div>
{{ $batches->links() }}
@else
<form method="get" class="d-filter"><label>Mã máy <input name="q" placeholder="Tìm mã máy" value="{{ request('q') }}"></label><label>Trạng thái <select name="status"><option value="">Tất cả</option>@foreach($labels as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }} ({{ $totals[$key] }})</option>@endforeach</select></label><button>Lọc</button></form>
<div class="scroll d-table"><table><thead><tr><th>Mã máy</th><th>Tài xế</th><th>Thiếu giấy</th><th>Hết hạn</th><th>Cần duyệt</th><th>Hồ sơ</th></tr></thead><tbody>
@forelse($items as $item)
<tr><td><strong>{{ $item['machine']->asset_code }}</strong><small>{{ $item['machine']->machine_type }}</small></td>
<td>{{ $item['drivers']->count() }} tài xế</td>
@foreach(['missing','expired','review'] as $status)
<td><span @if(($item['counts'][$status] ?? 0) > 0) class="{{ $status === 'expired' ? 'd-danger' : 'd-warning' }}" @endif>{{ $item['counts'][$status] ?? 0 }}</span></td>
@endforeach
<td><a href="{{ route('dossiers.machine',$item['machine']) }}">Mở hồ sơ<span class="visually-hidden"> {{ $item['machine']->asset_code }}</span></a></td></tr>
@empty<tr><td colspan="6">Không tìm thấy máy phù hợp.</td></tr>@endforelse
</tbody></table></div>
@endif
</main>
@endsection
