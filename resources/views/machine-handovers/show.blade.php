@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div><div class="text-primary fw-bold small">OCR BIÊN BẢN BÀN GIAO</div><h1 class="h4 mb-1">{{ $case->machine->asset_code }}</h1><div class="text-muted">OCR chỉ gợi ý; dữ liệu chỉ có hiệu lực sau khi anh xác nhận.</div></div>
        <span class="badge bg-secondary">{{ $case->status }}</span>
    </div>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="row g-3">
        <div class="col-lg-8"><div class="card p-4">
            <h2 class="h6 mb-3">Xác nhận thông tin tối thiểu</h2>
            <form method="POST" action="{{ route('machine-handovers.confirm', $case) }}">@csrf
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Máy</label><input class="form-control" value="{{ $case->machine->asset_code }}" readonly><div class="form-text">Mã OCR: {{ $case->extracted_asset_code ?: 'Chưa đọc được' }}</div></div>
                    <div class="col-md-6"><label class="form-label">Ngày bàn giao</label><input type="date" name="handover_date" class="form-control" required value="{{ old('handover_date', $case->handover_date?->format('Y-m-d')) }}"></div>
                    <div class="col-md-6"><label class="form-label">Dự án</label>
                    @if($case->machine->intakeCase?->project_id)
                        <input type="hidden" name="project_id" value="{{ $case->machine->intakeCase->project_id }}"><input class="form-control" readonly value="{{ $case->machine->intakeCase->project?->name }}">
                    @else
                        <select name="project_id" class="form-select" required><option value="">-- Chọn dự án --</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(old('project_id', $case->project_id) == $project->id)>{{ $project->name }}</option>@endforeach</select>
                    @endif
                    <div class="form-text">Dự án hồ sơ là nguồn chính · OCR đọc: {{ $case->extracted_project_text ?: 'Không rõ' }}</div></div>
                    <div class="col-md-6"><label class="form-label">Ban chỉ huy</label><select name="command_center_id" class="form-select" required>
                        <option value="">-- Chọn BCH từ Laravel --</option>@foreach($commandCenters as $center)<option value="{{ $center->id }}" @selected(old('command_center_id', $case->command_center_id) == $center->id)>{{ $center->name }}</option>@endforeach
                    </select><div class="form-text">OCR đọc: {{ $case->extracted_command_center_text ?: 'Không rõ — chọn thủ công' }}</div></div>
                </div>
                @if($case->status !== 'HANDED_OVER')<button class="btn btn-primary mt-4">Xác nhận bàn giao → Chờ kích hoạt</button>@endif
            </form>
        </div></div>
        <div class="col-lg-4">
            <div class="card p-3 mb-3"><h2 class="h6">Ảnh gốc ({{ $case->documents->count() }})</h2>@foreach($case->documents as $document)<a class="d-block mb-2" href="{{ route('machine-handovers.documents.show', [$case, $document]) }}">{{ $document->original_name }}</a><span class="small text-muted">{{ $document->extraction_status }}</span>@endforeach</div>
            <div class="card p-3"><h2 class="h6">Cảnh báo OCR</h2>@forelse($case->review_flags ?? [] as $flag)<div class="small mb-1">• {{ $flag }}</div>@empty<div class="text-success">Không có cảnh báo.</div>@endforelse
                <hr><div class="small text-muted">Các trường GPS, số giờ, người giao nhận và tình trạng kỹ thuật được lưu làm tham khảo, không chặn bàn giao.</div>
            </div>
        </div>
    </div>
</div>
@endsection
