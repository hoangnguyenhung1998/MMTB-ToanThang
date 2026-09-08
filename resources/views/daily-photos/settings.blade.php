@extends('layouts.app')
@section('content')
<div class="container py-4">
<h1>Người gửi Zalo và lái máy</h1>
<p>Ánh xạ theo ID người gửi và thời gian Việt Nam. Chỉ xác định máy khi có duy nhất một phân công lái máy phù hợp.</p>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('daily-photos.link') }}" class="row g-3 mb-4">
@csrf
<label class="col-md-4">Người gửi <input class="form-control" name="sender_id" list="known-senders" required value="{{ old('sender_id') }}"></label>
<datalist id="known-senders">@foreach($senders as $sender)<option value="{{ $sender->sender_id }}">{{ $sender->sender_name }}</option>@endforeach</datalist>
<label class="col-md-4">Lái máy <select name="driver_id" class="form-select" required><option value="">Chọn lái máy</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}">{{ $driver->name }}</option>@endforeach</select></label>
<label class="col-md-4">Có hiệu lực từ <input type="datetime-local" name="valid_from" class="form-control" required></label>
<label class="col-md-4">Đến thời điểm <input type="datetime-local" name="valid_to" class="form-control"></label>
<div><button class="btn btn-primary">Lưu ánh xạ</button></div>
</form>
<div class="table-responsive"><table class="table"><thead><tr><th>ID Zalo</th><th>Lái máy</th><th>Từ</th><th>Đến</th></tr></thead><tbody>
@foreach($links as $link)<tr><td>{{ $link->sender_id }}</td><td>{{ $link->name }}</td><td>{{ $link->valid_from }}</td><td>
{{ $link->valid_to ?: 'Đang hiệu lực' }}
@if(!$link->valid_to)<form method="POST" action="{{ route('daily-photos.close-link', $link->id) }}">@csrf
<label>Kết thúc lúc <input type="datetime-local" name="valid_to" required></label><button class="btn btn-sm btn-outline-secondary">Kết thúc ánh xạ</button></form>@endif
</td></tr>@endforeach
</tbody></table></div>{{ $links->links() }}
</div>
@endsection
