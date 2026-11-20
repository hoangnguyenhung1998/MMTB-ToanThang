@extends('layouts.app')
@section('content')
<div class="container py-4">
<h1>Người gửi Zalo và máy mặc định</h1>
<p>Mã máy hợp lệ đọc từ ảnh luôn được ưu tiên. Mapping chỉ fallback theo đúng lịch sử hiệu lực; lưu mapping không tự chạy backlog.</p>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

@if($recoveryRuns->isNotEmpty())
<div class="mb-3"><strong>Yêu cầu xử lý gần đây</strong>
@foreach($recoveryRuns as $recoveryRun)<div><a href="{{ route('daily-photos.recovery.show', $recoveryRun) }}">{{ $recoveryRun->filters['sender_id'] ?? 'CLI' }} — {{ ['QUEUED'=>'Đang chờ','RUNNING'=>'Đang xử lý','COMPLETED'=>'Hoàn tất','FAILED'=>'Có lỗi cần xử lý'][$recoveryRun->status] }}</a></div>@endforeach
</div>
@endif
<div class="row g-3 mb-4">
    <div class="col"><div class="card card-body"><small>Tổng exception</small><strong class="fs-4">{{ number_format($report['total']) }}</strong></div></div>
    <div class="col"><div class="card card-body"><small>Có thể tự xử lý</small><strong class="fs-4 text-success">{{ number_format($report['auto_recoverable']) }}</strong></div></div>
    <div class="col"><div class="card card-body"><small>Cần manual</small><strong class="fs-4 text-warning">{{ number_format($report['manual']) }}</strong></div></div>
    <div class="col"><div class="card card-body"><small>Chưa mapping</small><strong class="fs-4 text-danger">{{ number_format($report['unmapped']) }}</strong></div></div>
</div>

@can('manage-dossiers')
@if($mappingReviews->isNotEmpty())
<details><summary>OCR báo khác máy đang gắn ({{ $mappingReviews->count() }} gần nhất)</summary>
<p>Đối chiếu ảnh trước khi quyết định. Máy đang gắn được giữ nguyên.</p>
@foreach($mappingReviews as $review)
@php($conflict = $review->machine_resolution_metadata['sender_mapping_review'])
<div>OCR #{{ $review->id }} · <a href="{{ route('daily-photos.settings',['sender_id'=>$conflict['sender_id']]) }}">{{ $conflict['sender_id'] }}</a>
 · Máy đang gắn lúc đọc: {{ $machines->whereIn('id',$conflict['assigned_machine_ids'])->pluck('asset_code')->implode(', ') }}
 · Mã trên ảnh: {{ $machines->firstWhere('id',$conflict['observed_machine_id'])?->asset_code }}</div>
@endforeach
</details>
@endif
@if($mappingIssues->isNotEmpty())
<details><summary>Ánh xạ cần đối chiếu ({{ $mappingIssues->count() }})</summary>
@foreach($mappingIssues as $issue)<div>{{ $issue['sender_id'] ?? 'Nhiều sender' }} · {{ $issue['reason'] }}</div>@endforeach
</details>
@endif
@endcan
<form method="POST" action="{{ route('daily-photos.link') }}" class="row g-3 mb-4">
@csrf
<label class="col-md-5">Người gửi / ID Zalo <input class="form-control" name="sender_id" list="known-senders" required value="{{ old('sender_id',$senderFocus) }}"></label>
<datalist id="known-senders">@foreach($allSenders as $sender)<option value="{{ $sender['sender_id'] }}">{{ $sender['sender_name'] }}</option>@endforeach</datalist>
<label class="col-md-5">Máy mặc định <select name="machine_id" class="form-select" required><option value="">Chọn máy</option>@foreach($machines as $machine)<option value="{{ $machine->id }}" @selected($focusMachine===$machine->id)>{{ $machine->asset_code }}</option>@endforeach</select></label>
<div><button class="btn btn-primary">Lưu ánh xạ</button></div>
</form>

<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Sender</th><th>Current mapping</th><th>Ảnh exception</th><th>Có thể auto-recover</th><th>Cần manual</th><th>Thời điểm mapping</th><th>Thao tác</th></tr></thead><tbody>
@foreach($senders as $sender)<tr>
<td><strong>{{ $sender['sender_name'] ?: 'Không rõ tên' }}</strong><br><small>{{ $sender['sender_id'] }}</small>
@can('manage-dossiers')
@php($driverIds = $dossierIdentities[$sender['sender_id']] ?? collect())
@php($profileDriver = $driverIds->count() === 1 ? $dossierDrivers->firstWhere('id',$driverIds->first()) : null)
@if($profileDriver)<p><a href="{{ route('dossiers.driver',$profileDriver) }}">Hồ sơ: {{ $profileDriver->name }}</a></p>
@else<details><summary>Chưa liên kết hồ sơ tài xế</summary><p>Chọn đúng người; liên kết này không thay mapping/OCR.</p>
<form method="post" class="dossier-profile-link">@csrf<input type="hidden" name="canonical" value="1"><input type="hidden" name="sender_id" value="{{ $sender['sender_id'] }}"><select required aria-label="Tài xế của sender" onchange="this.form.action=this.value"><option value="">Chọn tài xế</option>@foreach($dossierDrivers as $d)<option value="{{ route('dossiers.sender',$d) }}">{{ $d->name }} (#{{ $d->id }})</option>@endforeach</select><button type="submit" class="btn btn-sm btn-outline-primary">Xác nhận liên kết hồ sơ</button></form></details>@endif
@endcan
</td>
<td>{{ $sender['mapping']?->machine?->asset_code ?? 'Chưa mapping' }}
@can('manage-dossiers') @foreach($mappingIssues->where('sender_id',$sender['sender_id']) as $issue)<div class="text-warning">{{ $issue['reason'] }}</div>@endforeach @endcan</td>
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
        <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        @foreach(['date_from', 'date_to', 'reason', 'command_center_id', 'project_id'] as $filter)
        @if(request()->filled($filter))<input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">@endif
        @endforeach
        <input type="hidden" name="sender_id" value="{{ $sender['sender_id'] }}">
        <div class="small mb-1">Có {{ number_format($sender['waiting']) }} ảnh đang chờ: {{ number_format($sender['auto_recoverable']) }} có thể xử lý, {{ number_format($sender['manual']) }} cần manual.</div>
        <button class="btn btn-sm btn-success" onclick="return confirm('Chỉ xử lý ảnh đủ điều kiện theo lịch sử mapping. Tiếp tục?')">XỬ LÝ ẢNH ĐANG CHỜ</button>
    </form>
    @endif
</td></tr>@endforeach
</tbody></table></div>
{{ $senders->links() }}

<h2 class="h5 mt-4">Phân loại backlog read-only</h2>
<table class="table table-sm"><thead><tr><th>Reason</th><th>Mô tả</th><th>Số ảnh</th></tr></thead><tbody>
@foreach($report['by_reason'] as $reason => $count)<tr><td><code>{{ $reason }}</code></td><td>{{ $reasonLabels[$reason] ?? 'Ngoại lệ khác' }}</td><td>{{ number_format($count) }}</td></tr>@endforeach
</tbody></table>
</div>
@endsection
