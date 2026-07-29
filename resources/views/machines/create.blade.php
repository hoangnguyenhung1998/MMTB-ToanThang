@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Thêm máy mới</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('machines.store') }}" enctype="multipart/form-data" class="card p-3">
            @csrf
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Mã máy</label>
                    <input type="text" name="asset_code" class="form-control" value="{{ old('asset_code') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Công ty</label>
                    <select name="company" class="form-select" required>
                        <option value="">-- Chọn công ty --</option>
                        <option value="VINCONS" @selected(old('company') === 'VINCONS')>VINCONS</option>
                        <option value="VINALPHA" @selected(old('company') === 'VINALPHA')>VINALPHA</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Số khung</label>
                    <input type="text" name="chassis_no" class="form-control" value="{{ old('chassis_no') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Số máy</label>
                    <input type="text" name="engine_no" class="form-control" value="{{ old('engine_no') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Biển số</label>
                    <input type="text" name="plate_no" class="form-control" value="{{ old('plate_no') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Loại máy</label>
                    <input type="text" name="machine_type" class="form-control" value="{{ old('machine_type') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Năm sản xuất</label>
                    <input type="number" name="manufacture_year" class="form-control" value="{{ old('manufacture_year') }}" min="1900" max="{{ now()->year + 1 }}" placeholder="Ví dụ: 2021">
                </div>
            </div>

            <div class="card mt-4 p-3">
                <h2 class="h6 mb-3">Giấy tờ máy (không bắt buộc)</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Loại giấy tờ</label>
                        <select name="document_type" class="form-select">
                            <option value="">-- Chọn loại --</option>
                            <option value="Đăng ký" @selected(old('document_type') === 'Đăng ký')>Đăng ký</option>
                            <option value="Đăng kiểm" @selected(old('document_type') === 'Đăng kiểm')>Đăng kiểm</option>
                            <option value="Kiểm định" @selected(old('document_type') === 'Kiểm định')>Kiểm định</option>
                            <option value="Scan toàn bộ hồ sơ" @selected(old('document_type') === 'Scan toàn bộ hồ sơ')>Scan toàn bộ hồ sơ</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Tải file</label>
                        <input type="file" name="documents[]" class="form-control" multiple>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-primary" type="submit">Lưu</button>
                <a class="btn btn-outline-secondary" href="{{ route('machines.index') }}">Quay lại</a>
            </div>
        </form>
    </div>
@endsection
