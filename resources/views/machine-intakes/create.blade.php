@extends('layouts.app')
@section('content')
<div class="page-shell form-page-shell">
    <x-page-header eyebrow="AI tiếp nhận máy" title="Tạo hồ sơ từ ảnh" subtitle="Tải ảnh máy, đăng kiểm hoặc ảnh số khung/số máy. OCR intake sẽ được nối vào hồ sơ này.">
        <x-slot:actions><a class="btn btn-outline-secondary" href="{{ route('machine-intakes.index') }}">Quay lại</a></x-slot:actions>
    </x-page-header>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('machine-intakes.store') }}" enctype="multipart/form-data" class="form-workspace">@csrf
        <div class="form-main-column">
            <x-section-card number="01" title="Ảnh nguồn" subtitle="Có thể chọn nhiều ảnh của cùng một máy.">
                <div class="row g-3"><div class="col-md-5"><label class="form-label">Loại hồ sơ</label><select name="document_type" class="form-select"><option value="MACHINE_PHOTO">Ảnh máy</option><option value="REGISTRATION_CERTIFICATE">Đăng kiểm/đăng ký</option><option value="CHASSIS_PLATE">Ảnh số khung</option><option value="ENGINE_PLATE">Ảnh số máy</option><option value="OTHER">Khác</option></select></div><div class="col-md-7"><label class="form-label required-label">Ảnh nguồn</label><input required multiple type="file" name="documents[]" class="form-control" accept="image/jpeg,image/png,image/webp,image/bmp,image/tiff"><div class="form-hint">Mỗi hồ sơ chỉ gồm ảnh của một máy; Excel mẫu được hệ thống tạo ở bước gửi BCH.</div></div></div>
            </x-section-card>
            <x-section-card number="02" title="Thông tin đã biết" subtitle="Có thể để trống rồi bổ sung sau OCR.">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Công ty</label><select name="company" class="form-select"><option value="">Chưa xác định</option><x-company-options :selected="old('company')" /></select></div>
                    <div class="col-md-4"><label class="form-label">Số khung</label><input name="chassis_no" class="form-control" value="{{ old('chassis_no') }}"></div>
                    <div class="col-md-4"><label class="form-label">Số máy</label><input name="engine_no" class="form-control" value="{{ old('engine_no') }}"></div>
                    <div class="col-md-4"><label class="form-label">Loại máy</label><input name="machine_type" class="form-control" value="{{ old('machine_type') }}"></div>
                    <div class="col-md-4"><label class="form-label">Model</label><input name="model_name" class="form-control" value="{{ old('model_name') }}"></div>
                    <div class="col-md-4"><label class="form-label">Năm sản xuất</label><input type="number" name="manufacture_year" class="form-control" value="{{ old('manufacture_year') }}"></div>
                </div>
            </x-section-card>
        </div>
        <aside class="form-side-column"><div class="app-card p-4"><h3>Luồng tự động</h3><ol class="small text-muted ps-3"><li>Lưu hồ sơ và ảnh gốc</li><li>OCR tách số khung/số máy</li><li>Anh xác nhận dữ liệu</li><li>Gửi BCH, chờ mã độc lập</li><li>Có mã → tạo máy chờ bàn giao</li></ol><button class="btn btn-primary w-100">Tạo hồ sơ</button></div></aside>
    </form>
</div>
@endsection
