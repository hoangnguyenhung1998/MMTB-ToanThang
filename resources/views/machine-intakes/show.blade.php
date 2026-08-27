@extends('layouts.app')
@section('content')
<div class="page-shell">
    <x-page-header eyebrow="Hồ sơ tiếp nhận" title="{{ $case->reference }}" subtitle="Ảnh gốc và lịch sử được giữ nguyên để truy vết.">
        <x-slot:actions><a class="btn btn-outline-secondary" href="{{ route('machine-intakes.index') }}">Danh sách chờ</a>@if($case->machine)<a class="btn btn-primary" href="{{ route('machines.show', $case->machine) }}">Mở máy {{ $case->asset_code }}</a>@endif</x-slot:actions>
    </x-page-header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="row g-4"><div class="col-lg-8">
        <div class="app-card p-4 mb-4"><div class="d-flex justify-content-between mb-3"><h3 class="mb-0">Xác nhận định danh</h3><span class="badge bg-secondary">{{ $case->status }}</span></div>
        <form method="POST" action="{{ route('machine-intakes.confirm', $case) }}">@csrf @method('PUT')<div class="row g-3">
            <div class="col-md-4"><label class="form-label required-label">Công ty</label><select name="company" class="form-select" required><option value="">Chọn</option><option @selected($case->company==='VINCONS')>VINCONS</option><option @selected($case->company==='VINALPHA')>VINALPHA</option></select></div>
            <div class="col-md-4"><label class="form-label required-label">Số khung</label><input required name="chassis_no" class="form-control" value="{{ old('chassis_no',$case->chassis_no_raw ?: $case->chassis_no) }}"></div>
            <div class="col-md-4"><label class="form-label required-label">Số máy</label><input required name="engine_no" class="form-control" value="{{ old('engine_no',$case->engine_no_raw ?: $case->engine_no) }}"></div>
            <div class="col-md-4"><label class="form-label required-label">Loại máy</label><input required name="machine_type" class="form-control" value="{{ old('machine_type',$case->machine_type) }}"></div>
            <div class="col-md-4"><label class="form-label">Model</label><input name="model_name" class="form-control" value="{{ old('model_name',$case->model_name) }}"></div>
            <div class="col-md-4"><label class="form-label required-label">Năm sản xuất</label><input required type="number" name="manufacture_year" class="form-control" value="{{ old('manufacture_year',$case->manufacture_year) }}"></div>
            <div class="col-12"><button class="btn btn-primary" @disabled($case->machine_id)>Xác nhận chính xác</button></div>
        </div></form></div>
        @if($case->status === 'CONFIRMED')<div class="app-card p-4 mb-4"><h3>Đã gửi BCH?</h3><p class="text-muted">Giai đoạn này ghi nhận thủ công; email watcher sẽ tự cập nhật thread ở Phase 16.2.</p><form method="POST" action="{{ route('machine-intakes.email-sent',$case) }}">@csrf<div class="row g-2"><div class="col-md-5"><input class="form-control" name="email_thread_id" placeholder="Email thread ID (nếu có)"></div><div class="col-md-5"><input class="form-control" name="email_message_id" placeholder="Email message ID (nếu có)"></div><div class="col-md-2"><button class="btn btn-outline-primary w-100">Đã gửi</button></div></div></form></div>@endif
        @if(in_array($case->status,['CONFIRMED','EMAIL_SENT','WAIT_ASSET_CODE']))<div class="app-card p-4 mb-4"><h3>Ghi nhận mã máy</h3><p class="text-muted">Chọn đúng hồ sơ; mã từ email và nguồn ngoài dùng chung kiểm tra trùng.</p><form method="POST" enctype="multipart/form-data" action="{{ route('machine-intakes.assign-code',$case) }}">@csrf<div class="row g-3"><div class="col-md-4"><label class="form-label required-label">Mã máy</label><input required name="asset_code" class="form-control"></div><div class="col-md-4"><label class="form-label required-label">Nguồn</label><select required name="asset_code_source" class="form-select"><option value="EMAIL_REPLY">Email phản hồi</option><option value="ZALO_BCH">Zalo BCH</option><option value="PHONE">Điện thoại</option><option value="EXCEL">Excel</option><option value="OTHER">Nguồn khác</option></select></div><div class="col-md-4"><label class="form-label">Ảnh/file bằng chứng</label><input type="file" name="evidence" class="form-control"></div><div class="col-12"><textarea name="asset_code_source_note" class="form-control" placeholder="Ghi chú nguồn nhận mã"></textarea></div><div class="col-12"><button class="btn btn-success">Xác nhận mã và tạo máy</button></div></div></form></div>@endif
    </div><div class="col-lg-4">
        <div class="app-card p-4 mb-4"><div class="d-flex justify-content-between align-items-center mb-3"><h3 class="mb-0">Tài liệu nguồn ({{ $case->documents->count() }})</h3><form method="POST" action="{{ route('machine-intakes.requeue',$case) }}">@csrf<button class="btn btn-sm btn-outline-primary">OCR lại</button></form></div>
        @if($case->review_flags)<div class="alert alert-warning py-2"><strong>Cần kiểm tra:</strong> {{ implode(', ',$case->review_flags) }}</div>@endif
        @foreach($case->documents as $doc)<div class="border rounded p-2 mb-3"><a href="{{ route('machine-intakes.documents.show',[$case,$doc]) }}" target="_blank"><img src="{{ route('machine-intakes.documents.show',[$case,$doc]) }}" alt="{{ $doc->original_name }}" class="w-100 rounded mb-2" style="max-height:260px;object-fit:contain;background:#f4f6fa"></a><strong>{{ $doc->document_type }}</strong><div class="small text-muted text-break">{{ $doc->original_name }}</div><span class="badge bg-secondary">{{ $doc->extraction_status }}</span>@if($doc->confidence)<span class="small ms-2">Tin cậy {{ number_format($doc->confidence*100) }}%</span>@endif</div>@endforeach</div>
        <div class="app-card p-4"><h3>Lịch sử bất biến</h3>@foreach($case->events as $event)<div class="border-start ps-3 pb-3"><strong>{{ $event->event }}</strong><div class="small text-muted">{{ $event->occurred_at->format('d/m/Y H:i:s') }} · {{ $event->user?->name ?: 'Hệ thống' }}</div></div>@endforeach</div>
    </div></div>
</div>
@if($case->documents->contains(fn($document) => in_array($document->extraction_status,['QUEUED','PROCESSING','RETRY'],true)))
<script>setTimeout(() => window.location.reload(), 10000);</script>
@endif
@endsection
