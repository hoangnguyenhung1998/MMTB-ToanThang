@extends('layouts.app')
@section('content')
<div class="container py-4">
<h1>Người gửi Zalo và máy mặc định</h1>
<p>Mã máy hợp lệ đọc từ ảnh luôn được ưu tiên. Mapping chỉ fallback theo đúng lịch sử hiệu lực; lưu mapping không tự chạy backlog.</p>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

<div class="row g-3 mb-4">
    <div class="col"><div class="card card-body"><small>Tổng exception</small><strong class="fs-4">{{ number_format($report['total']) }}</strong></div></div>
    <div class="col"><div class="card card-body"><small>Có thể tự xử lý</small><strong class="fs-4 text-success">{{ number_format($report['auto_recoverable']) }}</strong></div></div>
    <div class="col"><div class="card card-body"><small>Cần manual</small><strong class="fs-4 text-warning">{{ number_format($report['manual']) }}</strong></div></div>
    <div class="col"><div class="card card-body"><small>Chưa mapping</small><strong class="fs-4 text-danger">{{ number_format($report['unmapped']) }}</strong></div></div>
</div>

<form method="POST" action="{{ route('daily-photos.link') }}" class="row g-3 mb-4">
@csrf
<label class="col-md-5">Người gửi / ID Zalo <input class="form-control" name="sender_id" list="known-senders" required value="{{ old('sender_id') }}"></label>
<datalist id="known-senders">@foreach($senders as $sender)<option value="{{ $sender['sender_id'] }}">{{ $sender['sender_name'] }}</option>@endforeach</datalist>
<label class="col-md-5">Máy mặc định <select name="machine_id" class="form-select" required><option value="">Chọn máy</option>@foreach($machines as $machine)<option value="{{ $machine->id }}">{{ $machine->asset_code }}</option>@endforeach</select></label>
<div><button class="btn btn-primary">Lưu ánh xạ</button></div>
</form>

<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Sender</th><th>Current mapping</th><th>Ảnh exception</th><th>Có thể auto-recover</th><th>Cần manual</th><th>Thời điểm mapping</th><th>Thao tác</th></tr></thead><tbody>
@foreach($senders as $sender)<tr>
<td><strong>{{ $sender['sender_name'] ?: 'Không rõ tên' }}</strong><br><small>{{ $sender['sender_id'] }}</small></td>
<td>{{ $sender['mapping']?->machine?->asset_code ?? 'Chưa mapping' }}</td>
<td>{{ number_format($sender['waiting']) }} ảnh chờ</td>
<td class="text-success">{{ number_format($sender['auto_recoverable']) }}</td>
<td class="text-warning">{{ number_format($sender['manual']) }}</td>
<td>{{ ($sender['mapping']?->valid_from)?->copy()->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') ?? (($sender['legacy_mapping'] ?? false) ? 'Ánh xạ hiện có' : '—') }}</td>
<td>
    <form method="POST" action="{{ route('daily-photos.link') }}" class="d-flex gap-2 mb-2">@csrf
        <input type="hidden" name="sender_id" value="{{ $sender['sender_id'] }}">
        <select name="machine_id" class="form-select form-select-sm" required aria-label="Máy mặc định"><option value="">Chọn máy</option>@foreach($machines as $machine)<option value="{{ $machine->id }}" @selected($sender['mapping']?->machine_id === $machine->id)>{{ $machine->asset_code }}</option>@endforeach</select>
        <button class="btn btn-sm btn-outline-primary">{{ $sender['mapping'] ? 'Sửa mapping' : 'Tạo mapping' }}</button>
    </form>
    @if($sender['auto_recoverable'] > 0)
    <form method="POST" action="{{ route('daily-photos.recover') }}">@csrf
        <input type="hidden" name="sender_id" value="{{ $sender['sender_id'] }}">
        <div class="small mb-1">Có {{ number_format($sender['waiting']) }} ảnh đang chờ: {{ number_format($sender['auto_recoverable']) }} có thể xử lý, {{ number_format($sender['manual']) }} cần manual.</div>
        <button class="btn btn-sm btn-success" onclick="return confirm('Chỉ xử lý ảnh đủ điều kiện theo lịch sử mapping. Tiếp tục?')">XỬ LÝ ẢNH ĐANG CHỜ</button>
    </form>
    @endif
</td></tr>@endforeach
</tbody></table></div>

<h2 class="h5 mt-4">Phân loại backlog read-only</h2>
<table class="table table-sm"><thead><tr><th>Reason</th><th>Mô tả</th><th>Số ảnh</th></tr></thead><tbody>
@foreach($report['by_reason'] as $reason => $count)<tr><td><code>{{ $reason }}</code></td><td>{{ $reasonLabels[$reason] ?? 'Ngoại lệ khác' }}</td><td>{{ number_format($count) }}</td></tr>@endforeach
</tbody></table>
</div>
@endsection
