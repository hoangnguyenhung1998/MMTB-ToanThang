@extends('layouts.app')

@section('content')
<div class="page-shell form-page-shell">
    <x-page-header
        eyebrow="Quản lý thiết bị"
        title="Thêm thiết bị mới"
        subtitle="Khai báo thông tin nhận diện, thông số cơ bản và hồ sơ ban đầu của thiết bị."
    >
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('machines.index') }}">Quay lại danh sách</a>
        </x-slot:actions>
    </x-page-header>

    @if ($errors->any())
        <div class="alert alert-danger">
            <div class="fw-bold mb-1">Chưa thể lưu thiết bị</div>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('machines.store') }}" enctype="multipart/form-data" class="form-workspace">
        @csrf

        <div class="form-main-column">
            <x-section-card number="01" title="Thông tin nhận diện" subtitle="Các thông tin dùng để tìm kiếm và phân biệt thiết bị.">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required-label">Mã máy</label>
                        <input type="text" name="asset_code" class="form-control" value="{{ old('asset_code') }}" placeholder="Ví dụ: VT-XL1140" required autofocus>
                        <div class="form-hint">Mã tài sản nội bộ, nên nhập đúng quy ước của công ty.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required-label">Số khung</label>
                        <input type="text" name="chassis_no" class="form-control" value="{{ old('chassis_no') }}" placeholder="Nhập số khung" required>
                        <div class="form-hint">Thông tin định danh chính để tránh trùng thiết bị.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Số máy</label>
                        <input type="text" name="engine_no" class="form-control" value="{{ old('engine_no') }}" placeholder="Nhập số máy nếu có">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Biển số</label>
                        <input type="text" name="plate_no" class="form-control" value="{{ old('plate_no') }}" placeholder="Ví dụ: 15C-123.45">
                    </div>
                </div>
            </x-section-card>

            <x-section-card number="02" title="Thông tin kỹ thuật và quản lý" subtitle="Phân loại thiết bị theo đơn vị sở hữu và đặc điểm kỹ thuật.">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label required-label">Công ty</label>
                        <select name="company" class="form-select" required>
                            <option value="">Chọn công ty</option>
                            <x-company-options :selected="old('company')" />
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Loại máy</label>
                        <input type="text" name="machine_type" class="form-control" value="{{ old('machine_type') }}" placeholder="Ví dụ: Máy xúc lốp">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Năm sản xuất</label>
                        <input type="number" name="manufacture_year" class="form-control" value="{{ old('manufacture_year') }}" min="1900" max="{{ now()->year + 1 }}" placeholder="Ví dụ: 2021">
                    </div>
                </div>
            </x-section-card>

            <x-section-card number="03" title="Hồ sơ đính kèm" subtitle="Có thể bổ sung hoặc quản lý chi tiết sau khi tạo máy." optional>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Loại giấy tờ</label>
                        <select name="document_type" class="form-select">
                            <option value="">Chọn loại giấy tờ</option>
                            <option value="Đăng ký" @selected(old('document_type') === 'Đăng ký')>Đăng ký</option>
                            <option value="Đăng kiểm" @selected(old('document_type') === 'Đăng kiểm')>Đăng kiểm</option>
                            <option value="Kiểm định" @selected(old('document_type') === 'Kiểm định')>Kiểm định</option>
                            <option value="Scan toàn bộ hồ sơ" @selected(old('document_type') === 'Scan toàn bộ hồ sơ')>Scan toàn bộ hồ sơ</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Tệp hồ sơ</label>
                        <input type="file" name="documents[]" class="form-control" multiple>
                        <div class="form-hint">Có thể chọn nhiều tệp cùng lúc.</div>
                    </div>
                </div>
            </x-section-card>
        </div>

        <aside class="form-side-column">
            <div class="app-card form-summary-card sticky-form-card">
                <div class="summary-card-icon">🚜</div>
                <h3>Tạo thiết bị</h3>
                <p>Kiểm tra lại các trường bắt buộc trước khi lưu.</p>
                <div class="summary-checklist">
                    <div><span>✓</span> Mã máy</div>
                    <div><span>✓</span> Số khung</div>
                    <div><span>✓</span> Công ty</div>
                </div>
                <button class="btn btn-primary w-100" type="submit">Lưu thiết bị</button>
                <a class="btn btn-outline-secondary w-100 mt-2" href="{{ route('machines.index') }}">Hủy</a>
            </div>
        </aside>
    </form>
</div>
@endsection
