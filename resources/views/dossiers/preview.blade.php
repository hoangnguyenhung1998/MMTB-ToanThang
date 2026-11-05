@extends('layouts.app')
@section('content')
<main class="dossier">@include('dossiers.common')<h1>Xem trước bộ {{ $machine->asset_code }}</h1>
<div class="box">@forelse($preview['warnings'] as $warning)<p class="error">{{ $warning }}</p>@empty<p>Không có cảnh báo từ hồ sơ hiện tại.</p>@endforelse</div>
<form method="post" action="{{ route('dossiers.exports.create',$machine) }}">@csrf<input type="hidden" name="action" value="create"><input type="hidden" name="request_id" value="{{ $requestId }}">
@foreach($preview['drivers'] as $id)<input type="hidden" name="driver_ids[]" value="{{ $id }}">@endforeach
<p>Thứ tự dưới đây là thứ tự PDF. Dùng nút lên/xuống; quay lại để thay lựa chọn giấy. Trang dùng chung chỉ xuất một lần.</p>
<div id="ordered">@foreach($preview['manifest'] as $item)<div class="box order-item"><input type="hidden" name="version_ids[]" value="{{ $item['version_id'] }}">#{{ $item['version_id'] }} — {{ $item['type'] }} / {{ $item['subject_name'] }} — trang {{ implode(',',array_column($item['original_pages'],'page')) }} <a href="{{ route('dossiers.file',[$item['source_id'],'source']) }}" target="_blank" rel="noopener">Xem nguồn</a><button type="button" class="up" aria-label="Đưa giấy lên">↑</button><button type="button" class="down" aria-label="Đưa giấy xuống">↓</button></div>@endforeach</div>
<label><input type="checkbox" name="acknowledged" value="1" @required(count($preview['warnings'])>0)> Tôi đã đọc các cảnh báo và chấp nhận bộ đã chọn.</label><button>Yêu cầu gộp PDF</button>
</form></main>
<script>document.querySelectorAll('.up').forEach(b=>b.onclick=()=>{const item=b.closest('.order-item');if(item.previousElementSibling)item.parentElement.insertBefore(item,item.previousElementSibling);});document.querySelectorAll('.down').forEach(b=>b.onclick=()=>{const item=b.closest('.order-item');if(item.nextElementSibling)item.parentElement.insertBefore(item.nextElementSibling,item);});</script>
@endsection
