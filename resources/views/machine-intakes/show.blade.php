@extends('layouts.app')
@section('content')
<div class="page-shell">
    <x-page-header eyebrow="Hồ sơ tiếp nhận" title="{{ $case->reference }}" subtitle="Ảnh gốc và lịch sử được giữ nguyên để truy vết.">
        <x-slot:actions><a class="btn btn-outline-secondary" href="{{ route('machine-intakes.index') }}">Danh sách chờ</a>@if($case->machine)<a class="btn btn-primary" href="{{ route('machines.show', $case->machine) }}">Mở máy {{ $case->asset_code }}</a>@endif</x-slot:actions>
    </x-page-header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if($case->status==='DUPLICATE')
        <div class="alert alert-secondary">
            <strong>Hồ sơ đã đóng do trùng.</strong> {{ $case->duplicate_reason }}
            @if($case->duplicate_machine_id)
                · <a class="alert-link" href="{{ route('machines.show',$case->duplicate_machine_id) }}">Mở máy đã tồn tại</a>
            @endif
        </div>
    @elseif($duplicateConflict)
        <div class="alert alert-danger d-flex justify-content-between align-items-center gap-3">
            <div>
                <strong>Phát hiện trùng số khung.</strong>
                @if($duplicateConflict['type']==='machine')
                    Số khung này đã thuộc máy <a class="alert-link" href="{{ route('machines.show',$duplicateConflict['machine']) }}">{{ $duplicateConflict['machine']->asset_code }}</a>.
                @else
                    Số khung này đang nằm trong hồ sơ <a class="alert-link" href="{{ route('machine-intakes.show',$duplicateConflict['intake']) }}">{{ $duplicateConflict['intake']->reference }}</a>.
                @endif
                Không được gửi BCH hoặc tạo thêm máy.
            </div>
            @if(!$case->machine_id)
                <form method="POST" action="{{ route('machine-intakes.close-duplicate',$case) }}" onsubmit="return confirm('Đóng hồ sơ này vì trùng số khung?')">@csrf<button class="btn btn-danger text-nowrap">Đóng hồ sơ trùng</button></form>
            @endif
        </div>
    @endif
    <div class="row g-4"><div class="col-lg-8">
        <div class="app-card p-4 mb-4"><div class="d-flex justify-content-between mb-3"><h3 class="mb-0">Xác nhận định danh</h3><span class="badge bg-secondary">{{ $case->status }}</span></div>
        <form method="POST" action="{{ route('machine-intakes.confirm', $case) }}">@csrf @method('PUT')<div class="row g-3">
            <div class="col-md-4"><label class="form-label required-label">Công ty</label><select name="company" class="form-select" required><option value="">Chọn</option><x-company-options :selected="old('company', $case->company)" /></select></div>
            <div class="col-md-4"><label class="form-label required-label">Số khung</label><input required name="chassis_no" class="form-control" value="{{ old('chassis_no',$case->chassis_no_raw ?: $case->chassis_no) }}"></div>
            <div class="col-md-4"><label class="form-label required-label">Số máy</label><input required name="engine_no" class="form-control" value="{{ old('engine_no',$case->engine_no_raw ?: $case->engine_no) }}"></div>
            <div class="col-md-4"><label class="form-label required-label">Loại máy</label><input required name="machine_type" class="form-control" value="{{ old('machine_type',$case->machine_type) }}"></div>
            <div class="col-md-4"><label class="form-label">Nhãn hiệu</label><input name="brand" class="form-control" value="{{ old('brand',$case->brand) }}"></div>
            <div class="col-md-4"><label class="form-label">Model</label><input name="model_name" class="form-control" value="{{ old('model_name',$case->model_name) }}"></div>
            <div class="col-md-4"><label class="form-label">Biển số</label><input name="plate_no" class="form-control" value="{{ old('plate_no',$case->plate_no) }}"></div>
            <div class="col-md-4"><label class="form-label">Nhóm công suất</label><select name="capacity_class" class="form-select"><option value="">Không áp dụng</option>@foreach([55,140,200,300] as $v)<option value="{{ $v }}" @selected((int)$case->capacity_class===$v)>{{ $v }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label">Xe ô tô số chân</label><select name="vehicle_axles" class="form-select"><option value="">Không áp dụng</option>@foreach([2,3,4] as $v)<option value="{{ $v }}" @selected((int)$case->vehicle_axles===$v)>{{ $v }} chân</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label required-label">Năm sản xuất</label><input required type="number" name="manufacture_year" class="form-control" value="{{ old('manufacture_year',$case->manufacture_year) }}"></div>
            <div class="col-md-6"><label class="form-label">Dự án dự kiến</label><select name="project_id" class="form-select"><option value="">Chưa xác định</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((int)$case->project_id===$project->id)>{{ $project->name }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label">Ngày dự kiến về</label><input type="date" name="handover_at" class="form-control" value="{{ old('handover_at',$case->handover_at?->format('Y-m-d')) }}"></div>
            <div class="col-12"><button class="btn btn-primary" @disabled($case->machine_id || $case->status==='DUPLICATE')>Xác nhận chính xác</button></div>
        </div></form></div>
        @if($case->status==='CONFIRMED' && !$duplicateConflict)
            <div class="app-card p-4 mb-4">
                <h3>Tạo Excel và email gửi BCH</h3>
                <form method="POST" action="{{ route('machine-intakes.bch.prepare',$case) }}">@csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-label">Gửi bằng tài khoản</label>
                            <select required name="sender_profile" class="form-select">
                                @foreach($bchSenderOptions as $sender)
                                    <option value="{{ $sender['key'] }}" @selected(old('sender_profile',$case->bch_sender_profile ?: $defaultBchSender)===$sender['key'])>
                                        {{ $sender['label'] }}{{ $sender['address'] ? ' · '.$sender['address'] : '' }}{{ $sender['configured'] ? '' : ' · SMTP chưa sẵn sàng' }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Tạo Excel vẫn dùng được; hệ thống chỉ gửi khi tài khoản đã cấu hình SMTP.</div>
                        </div>
                        <div class="col-md-6"><label class="form-label required-label">Email nhận</label><input required type="text" name="to" class="form-control" value="{{ old('to',$case->bch_email_to) }}" placeholder="email@congty.vn"></div>
                        <div class="col-md-6"><label class="form-label">CC</label><input name="cc" class="form-control" value="{{ old('cc',$case->bch_email_cc) }}"></div>
                        <div class="col-12"><label class="form-label required-label">Tiêu đề</label><input required name="subject" class="form-control" value="{{ old('subject',$case->bch_email_subject ?: '[MMTB] Đề nghị cấp mã '.$case->reference.' - '.$case->chassis_no) }}"></div>
                        <div class="col-12"><label class="form-label required-label">Nội dung</label><textarea required name="body" class="form-control" rows="5">{{ old('body',$case->bch_email_body ?: "Kính gửi Ban Chỉ huy,\nĐề nghị cấp mã cho thiết bị theo hồ sơ đính kèm.\nSố khung: {$case->chassis_no}\nSố máy: {$case->engine_no}\nTrân trọng.") }}</textarea></div>
                        <div class="col-12"><button class="btn btn-outline-primary">Tạo lại Excel và xem trước</button></div>
                    </div>
                </form>
                @if($case->bch_package_path)
                    <div class="d-flex gap-2 mt-3">
                        <a class="btn btn-outline-secondary" href="{{ route('machine-intakes.bch.download',$case) }}">Tải Excel xem trước</a>
                        @php($selectedBchSender = collect($bchSenderOptions)->firstWhere('key', $case->bch_sender_profile))
                        <form method="POST" action="{{ route('machine-intakes.bch.send',$case) }}" onsubmit="return confirm('Xác nhận gửi bằng {{ $selectedBchSender['label'] ?? 'tài khoản đã chọn' }}?')">@csrf<button class="btn btn-success">Xác nhận gửi BCH</button></form>
                    </div>
                @endif
            </div>
        @endif
        @if($case->status === 'CONFIRMED' && !$duplicateConflict)<div class="app-card p-4 mb-4"><h3>Đã gửi BCH?</h3><p class="text-muted">Giai đoạn này ghi nhận thủ công; email watcher sẽ tự cập nhật thread ở Phase 16.2.</p><form method="POST" action="{{ route('machine-intakes.email-sent',$case) }}">@csrf<div class="row g-2"><div class="col-md-5"><input class="form-control" name="email_thread_id" placeholder="Email thread ID (nếu có)"></div><div class="col-md-5"><input class="form-control" name="email_message_id" placeholder="Email message ID (nếu có)"></div><div class="col-md-2"><button class="btn btn-outline-primary w-100">Đã gửi</button></div></div></form></div>@endif
        @if(in_array($case->status,['CONFIRMED','EMAIL_SENT','WAIT_ASSET_CODE']) && !$duplicateConflict)<div class="app-card p-4 mb-4"><h3>Ghi nhận mã máy</h3><p class="text-muted">Chọn đúng hồ sơ; mã từ email và nguồn ngoài dùng chung kiểm tra trùng.</p><form method="POST" enctype="multipart/form-data" action="{{ route('machine-intakes.assign-code',$case) }}">@csrf<div class="row g-3"><div class="col-md-4"><label class="form-label required-label">Mã máy</label><input required name="asset_code" class="form-control"></div><div class="col-md-4"><label class="form-label required-label">Nguồn</label><select required name="asset_code_source" class="form-select"><option value="EMAIL_REPLY">Email phản hồi</option><option value="ZALO_BCH">Zalo BCH</option><option value="PHONE">Điện thoại</option><option value="EXCEL">Excel</option><option value="OTHER">Nguồn khác</option></select></div><div class="col-md-4"><label class="form-label">Ảnh/file bằng chứng</label><input type="file" name="evidence" class="form-control"></div><div class="col-12"><textarea name="asset_code_source_note" class="form-control" placeholder="Ghi chú nguồn nhận mã"></textarea></div><div class="col-12"><button class="btn btn-success">Xác nhận mã và tạo máy</button></div></div></form></div>@endif
        @if($case->emailReplies->isNotEmpty())<div class="app-card p-4 mb-4"><h3>Phản hồi Gmail</h3>@foreach($case->emailReplies->sortByDesc('received_at') as $reply)<div class="border rounded p-3 mb-3"><div class="d-flex justify-content-between"><strong>{{ $reply->candidate_asset_code ?: 'Chưa đọc được mã' }}</strong><span class="badge {{ $reply->status==='PENDING' ? 'bg-warning text-dark' : ($reply->status==='CONFIRMED' ? 'bg-success' : 'bg-secondary') }}">{{ $reply->status }}</span></div><div class="small text-muted mt-1">{{ $reply->sender }} · {{ $reply->received_at?->format('d/m/Y H:i') }}</div><div class="mt-2">{{ $reply->subject }}</div>@if($reply->confidence!==null)<div class="small">Tin cậy: {{ number_format($reply->confidence*100) }}% · Ghép theo {{ $reply->match_method }}</div>@endif @if($reply->status==='PENDING' && !$case->machine_id && !$duplicateConflict)<form method="POST" class="mt-3" action="{{ route('machine-intakes.email-replies.confirm',[$case,$reply]) }}" onsubmit="return confirm('Xác nhận mã {{ $reply->candidate_asset_code }} và tạo máy WAIT_HANDOVER?')">@csrf<button class="btn btn-success">Xác nhận mã {{ $reply->candidate_asset_code }}</button></form>@endif</div>@endforeach</div>@endif
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
