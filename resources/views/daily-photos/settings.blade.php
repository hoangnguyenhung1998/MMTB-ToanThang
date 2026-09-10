@extends('layouts.app')
@section('content')
<div class="container py-4">
<h1>Người gửi Zalo và máy mặc định</h1>
<p>Mã máy đọc từ ảnh được ưu tiên. Máy mặc định dùng khi ảnh không đọc được mã máy. Thay đổi chỉ áp dụng cho ảnh nhận từ bây giờ.</p>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('daily-photos.link') }}" class="row g-3 mb-4">
@csrf
<label class="col-md-5">Người gửi / ID Zalo <input class="form-control" name="sender_id" list="known-senders" required value="{{ old('sender_id') }}"></label>
<datalist id="known-senders">@foreach($senders as $sender)<option value="{{ $sender->sender_id }}">{{ $sender->sender_name }}</option>@endforeach</datalist>
<label class="col-md-5">Máy mặc định <select name="machine_id" class="form-select" required><option value="">Chọn máy</option>@foreach($machines as $machine)<option value="{{ $machine->id }}">{{ $machine->asset_code }}</option>@endforeach</select></label>
<div><button class="btn btn-primary">Lưu ánh xạ</button></div>
</form>
<div class="table-responsive"><table class="table"><thead><tr><th>Người gửi / ID Zalo</th><th>Máy mặc định hiện tại</th><th>Cập nhật (giờ Việt Nam)</th><th>Thao tác</th></tr></thead><tbody>
@foreach($links->getCollection()->concat($legacyLinks) as $link)<tr><td>{{ $senders->firstWhere('sender_id', $link->sender_id)?->sender_name }} · {{ $link->sender_id }}</td><td>{{ $link->machine?->asset_code }}</td><td>{{ $link->valid_from?->copy()->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') ?? 'Ánh xạ hiện có' }}</td><td>
<form method="POST" action="{{ route('daily-photos.link') }}" class="d-flex gap-2">@csrf
<input type="hidden" name="sender_id" value="{{ $link->sender_id }}">
<select name="machine_id" class="form-select" required aria-label="Máy mặc định">@foreach($machines as $machine)<option value="{{ $machine->id }}" @selected($link->machine_id === $machine->id)>{{ $machine->asset_code }}</option>@endforeach</select>
<button class="btn btn-sm btn-outline-primary">Sửa mapping</button></form>
</td></tr>@endforeach
</tbody></table></div>{{ $links->links() }}
</div>
@endsection
