@extends('layouts.app')

@section('content')

<div class="page-shell form-page-shell">
    <div class="machine-edit-hero app-card">
        <div class="machine-edit-main">
            <div class="machine-avatar">🚜</div>
            <div>
                <div class="page-eyebrow">Cập nhật thiết bị</div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h1 class="page-title">{{ $machine->asset_code }}</h1>
                    <x-status-badge :status="$machine->status" />
                </div>
                <p class="page-subtitle mb-0">{{ $machine->machine_type ?? 'Chưa khai báo loại máy' }} · {{ $machine->company }}</p>
            </div>
        </div>
        <div class="page-actions">
            <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Quay lại chi tiết</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger mt-3">
            <div class="fw-bold mb-1">Chưa thể cập nhật thiết bị</div>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('machines.update', $machine) }}" class="form-workspace mt-3">
        @csrf
        @method('PUT')

        <div class="form-main-column">
            <x-section-card number="01" title="Thông tin nhận diện" subtitle="Các dữ liệu định danh của thiết bị.">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required-label">Mã máy</label>
                        <input type="text" name="asset_code" class="form-control" value="{{ old('asset_code', $machine->asset_code) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required-label">Số khung</label>
                        <input type="text" name="chassis_no" class="form-control" value="{{ old('chassis_no', $machine->chassis_no) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Số máy</label>
                        <input type="text" name="engine_no" class="form-control" value="{{ old('engine_no', $machine->engine_no) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Biển số</label>
                        <input type="text" name="plate_no" class="form-control" value="{{ old('plate_no', $machine->plate_no) }}">
                    </div>
                </div>
            </section>

            <section class="app-card form-section-card">
                <div class="section-heading">
                    <div class="section-icon">02</div>
                    <div>
                        <h2>Thông tin kỹ thuật</h2>
                        <p>Đơn vị sở hữu, loại máy và năm sản xuất.</p>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label required-label">Công ty</label>
                        <select name="company" class="form-select" required>
                            <option value="VINCONS" @selected(old('company', $machine->company) === 'VINCONS')>VINCONS</option>
                            <option value="VINALPHA" @selected(old('company', $machine->company) === 'VINALPHA')>VINALPHA</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Loại máy</label>
                        <input type="text" name="machine_type" class="form-control" value="{{ old('machine_type', $machine->machine_type) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Năm sản xuất</label>
                        <input type="number" name="manufacture_year" class="form-control" value="{{ old('manufacture_year', $machine->manufacture_year) }}" min="1900" max="{{ now()->year + 1 }}" placeholder="Ví dụ: 2021">
                    </div>
                </div>
            </section>

            <section class="app-card form-section-card status-edit-section">
                <div class="section-heading">
                    <div class="section-icon">03</div>
                    <div>
                        <h2>Trạng thái hệ thống</h2>
                        <p>Chỉ thay đổi khi cần hiệu chỉnh dữ liệu. Các nghiệp vụ hằng ngày nên thực hiện tại trang chi tiết máy.</p>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required-label">Trạng thái</label>
                        <select name="status" class="form-select" required>
                            @foreach ([
                                'WAIT_HANDOVER' => 'Chờ bàn giao',
                                'HANDED_OVER' => 'Chờ kích hoạt',
                                'ACTIVE' => 'Đang hoạt động',
                                'RETURNED' => 'Đã trả'
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $machine->status) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </x-section-card>
        </div>

        <aside class="form-side-column">
            <div class="app-card form-summary-card sticky-form-card">
                <div class="summary-card-icon">✏️</div>
                <h3>Lưu thay đổi</h3>
                <p>Các thay đổi chỉ được ghi nhận sau khi bấm cập nhật.</p>
                <div class="mini-info-list">
                    <div><span>Mã hiện tại</span><strong>{{ $machine->asset_code }}</strong></div>
                    <div><span>Công ty</span><strong>{{ $machine->company }}</strong></div>
                    <div><span>Trạng thái</span><strong><x-status-badge :status="$machine->status" /></strong></div>
                </div>
                <button class="btn btn-primary w-100" type="submit">Cập nhật thiết bị</button>
                <a class="btn btn-outline-secondary w-100 mt-2" href="{{ route('machines.show', $machine) }}">Hủy thay đổi</a>
            </div>
        </aside>
    </form>
</div>
@endsection
