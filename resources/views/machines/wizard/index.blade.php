@extends('layouts.app')

@section('content')
<div class="page-shell wizard-page">
    <div class="page-header">
        <div>
            <div class="page-eyebrow">Quản lý thiết bị</div>
            <h1 class="page-title">Thêm máy đầy đủ</h1>
            <p class="page-subtitle">Nhập thông tin máy, hồ sơ, tài xế và bàn giao trong một quy trình.</p>
        </div>

        <form method="POST" action="{{ route('machines.wizard.cancel') }}"
              onsubmit="return confirm('Hủy Wizard và xóa toàn bộ dữ liệu tạm?')">
            @csrf
            <button class="btn btn-outline-danger" type="submit">Hủy Wizard</button>
        </form>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <div class="fw-bold mb-1">Chưa thể tiếp tục</div>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $machine = $wizard['machine'] ?? [];
        $documents = $wizard['documents'] ?? [];
        $operation = $wizard['operation'] ?? [];
        $lastCompleted = (int) ($wizard['last_completed_step'] ?? 0);

        $steps = [
            1 => 'Thông tin máy',
            2 => 'Giấy tờ',
            3 => 'Tài xế & bàn giao',
            4 => 'Xác nhận',
        ];
    @endphp

    <div class="app-card wizard-progress">
        @foreach ($steps as $number => $title)
            <div class="wizard-progress-item {{ $currentStep === $number ? 'is-active' : '' }} {{ $number <= $lastCompleted ? 'is-done' : '' }}">
                <span class="wizard-step-number">{{ $number < $currentStep ? '✓' : $number }}</span>
                <span><small>Bước {{ $number }}</small><strong>{{ $title }}</strong></span>
            </div>
        @endforeach
    </div>

    <div class="app-card wizard-card">
        @if ($currentStep === 1)
            <div class="wizard-card-header">
                <div class="page-eyebrow">Bước 1/4</div>
                <h2>Thông tin máy</h2>
                <p>Dữ liệu được lưu tạm cho tới khi anh xác nhận hoàn tất.</p>
            </div>

            <form method="POST" action="{{ route('machines.wizard.step1.store') }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required-label">Mã máy</label>
                        <input type="text" name="asset_code" class="form-control"
                               value="{{ old('asset_code', $machine['asset_code'] ?? '') }}" required autofocus>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required-label">Số khung</label>
                        <input type="text" name="chassis_no" class="form-control"
                               value="{{ old('chassis_no', $machine['chassis_no'] ?? '') }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Số máy</label>
                        <input type="text" name="engine_no" class="form-control"
                               value="{{ old('engine_no', $machine['engine_no'] ?? '') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Biển số</label>
                        <input type="text" name="plate_no" class="form-control"
                               value="{{ old('plate_no', $machine['plate_no'] ?? '') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required-label">Công ty</label>
                        <select name="company" class="form-select" required>
                            <option value="">Chọn công ty</option>
                            <option value="VINCONS" @selected(old('company', $machine['company'] ?? '') === 'VINCONS')>VINCONS</option>
                            <option value="VINALPHA" @selected(old('company', $machine['company'] ?? '') === 'VINALPHA')>VINALPHA</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Loại máy</label>
                        <input type="text" name="machine_type" class="form-control"
                               value="{{ old('machine_type', $machine['machine_type'] ?? '') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Năm sản xuất</label>
                        <input type="number" name="manufacture_year" class="form-control"
                               value="{{ old('manufacture_year', $machine['manufacture_year'] ?? '') }}"
                               min="1900" max="{{ now()->year + 1 }}">
                    </div>
                </div>

                <div class="wizard-actions">
                    <a href="{{ route('machines.index') }}" class="btn btn-outline-secondary">Quay lại danh sách</a>
                    <button type="submit" class="btn btn-primary">Tiếp tục →</button>
                </div>
            </form>

        @elseif ($currentStep === 2)
            <div class="wizard-card-header">
                <div class="page-eyebrow">Bước 2/4</div>
                <h2>Giấy tờ máy</h2>
                <p>Có thể tải nhiều file cùng một loại hoặc bỏ qua để bổ sung sau.</p>
            </div>

            <form method="POST" action="{{ route('machines.wizard.step2.store') }}" enctype="multipart/form-data">
                @csrf

                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" id="skip_documents"
                           name="skip_documents" value="1"
                           @checked(old('skip_documents', $documents['skip'] ?? false))>
                    <label class="form-check-label" for="skip_documents">Bỏ qua giấy tờ và bổ sung sau</label>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Loại giấy tờ</label>
                        <select name="document_type" class="form-select">
                            <option value="">Chọn loại giấy tờ</option>
                            @foreach (['Đăng ký', 'Đăng kiểm', 'Kiểm định', 'Bảo hiểm', 'GPS', 'Scan toàn bộ hồ sơ'] as $type)
                                <option value="{{ $type }}" @selected(old('document_type', $documents['document_type'] ?? '') === $type)>
                                    {{ $type }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Tệp hồ sơ</label>
                        <input type="file" name="documents[]" class="form-control" multiple>
                        @if (!empty($documents['files']))
                            <div class="form-hint mt-2">
                                Đang lưu tạm {{ count($documents['files']) }} tệp. Chọn lại file sẽ thay thế danh sách này.
                            </div>
                        @endif
                    </div>
                    <div class="col-12">
                        <label class="form-label">Ghi chú hồ sơ</label>
                        <textarea name="document_note" class="form-control" rows="3">{{ old('document_note', $documents['document_note'] ?? '') }}</textarea>
                    </div>
                </div>

                <div class="wizard-actions">
                    <a href="{{ route('machines.wizard.step1') }}" class="btn btn-outline-secondary">← Quay lại</a>
                    <button type="submit" class="btn btn-primary">Tiếp tục →</button>
                </div>
            </form>

        @elseif ($currentStep === 3)
            <div class="wizard-card-header">
                <div class="page-eyebrow">Bước 3/4</div>
                <h2>Tài xế và bàn giao</h2>
                <p>BCH hiện được chọn độc lập với dự án, đúng theo cấu trúc dữ liệu hiện tại.</p>
            </div>

            <form method="POST" action="{{ route('machines.wizard.step3.store') }}" enctype="multipart/form-data">
                @csrf

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Tài xế</label>
                        <select name="driver_id" class="form-select">
                            <option value="">Chưa gán tài xế</option>
                            @foreach ($drivers as $item)
                                <option value="{{ $item->id }}"
                                    @selected((string) old('driver_id', $operation['driver_id'] ?? '') === (string) $item->id)>
                                    {{ $item->name }}{{ $item->phone ? ' – '.$item->phone : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 mt-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="handover_now"
                                   name="handover_now" value="1"
                                   @checked(old('handover_now', $operation['handover_now'] ?? false))>
                            <label class="form-check-label fw-bold" for="handover_now">
                                Bàn giao máy ngay sau khi tạo
                            </label>
                        </div>
                    </div>

                    <div class="col-md-6 handover-field">
                        <label class="form-label">Dự án</label>
                        <select name="project_id" class="form-select">
                            <option value="">Chọn dự án</option>
                            @foreach ($projects as $item)
                                <option value="{{ $item->id }}"
                                    @selected((string) old('project_id', $operation['project_id'] ?? '') === (string) $item->id)>
                                    {{ $item->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6 handover-field">
                        <label class="form-label">Ban chỉ huy</label>
                        <select name="command_center_id" class="form-select">
                            <option value="">Chọn BCH</option>
                            @foreach ($commandCenters as $item)
                                <option value="{{ $item->id }}"
                                    @selected((string) old('command_center_id', $operation['command_center_id'] ?? '') === (string) $item->id)>
                                    {{ $item->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6 handover-field">
                        <label class="form-label">Thời gian bàn giao</label>
                        <input type="datetime-local" name="handover_time" class="form-control"
                               value="{{ old('handover_time', $operation['handover_time'] ?? now()->format('Y-m-d\TH:i')) }}">
                    </div>

                    <div class="col-md-6 handover-field">
                        <label class="form-label required-label">Biên bản bàn giao</label>
                        <input type="file" name="proof_file" class="form-control">
                        @if (!empty($operation['proof_file']))
                            <div class="form-hint mt-2">
                                Đã lưu tạm: {{ $operation['proof_file']['original_name'] ?? 'Biên bản bàn giao' }}.
                                Chọn lại file sẽ thay thế.
                            </div>
                        @endif
                    </div>

                    <div class="col-12 handover-field">
                        <label class="form-label">Ghi chú bàn giao</label>
                        <input type="text" name="handover_note" class="form-control"
                               value="{{ old('handover_note', $operation['handover_note'] ?? '') }}">
                    </div>
                </div>

                <div class="wizard-actions">
                    <a href="{{ route('machines.wizard.step2') }}" class="btn btn-outline-secondary">← Quay lại</a>
                    <button type="submit" class="btn btn-primary">Kiểm tra thông tin →</button>
                </div>
            </form>

        @elseif ($currentStep === 4)
            <div class="wizard-card-header">
                <div class="page-eyebrow">Bước 4/4</div>
                <h2>Kiểm tra và xác nhận</h2>
                <p>Bấm hoàn tất để tạo dữ liệu thật trong hệ thống.</p>
            </div>

            <div class="wizard-review-grid">
                <div class="review-box">
                    <h3>Thông tin máy</h3>
                    <p><span>Mã máy</span><b>{{ $machine['asset_code'] ?? '-' }}</b></p>
                    <p><span>Công ty</span><b>{{ $machine['company'] ?? '-' }}</b></p>
                    <p><span>Số khung</span><b>{{ $machine['chassis_no'] ?? '-' }}</b></p>
                    <p><span>Số máy</span><b>{{ $machine['engine_no'] ?? '-' }}</b></p>
                    <p><span>Biển số</span><b>{{ $machine['plate_no'] ?? '-' }}</b></p>
                </div>

                <div class="review-box">
                    <h3>Hồ sơ</h3>
                    <p><span>Trạng thái</span><b>{{ ($documents['skip'] ?? false) ? 'Bổ sung sau' : 'Có hồ sơ' }}</b></p>
                    <p><span>Loại giấy tờ</span><b>{{ $documents['document_type'] ?? '-' }}</b></p>
                    <p><span>Số tệp</span><b>{{ count($documents['files'] ?? []) }}</b></p>
                    <p><span>Ghi chú</span><b>{{ $documents['document_note'] ?? '-' }}</b></p>
                </div>

                <div class="review-box">
                    <h3>Tài xế</h3>
                    <p><span>Tài xế</span><b>{{ optional($driver)->name ?? 'Chưa gán' }}</b></p>
                    <p><span>Điện thoại</span><b>{{ optional($driver)->phone ?? '-' }}</b></p>
                </div>

                <div class="review-box">
                    <h3>Bàn giao</h3>
                    <p><span>Bàn giao ngay</span><b>{{ ($operation['handover_now'] ?? false) ? 'Có' : 'Không' }}</b></p>
                    <p><span>Dự án</span><b>{{ optional($project)->name ?? '-' }}</b></p>
                    <p><span>Ban chỉ huy</span><b>{{ optional($commandCenter)->name ?? '-' }}</b></p>
                    <p><span>Thời gian</span><b>{{ $operation['handover_time'] ?? '-' }}</b></p>
                    <p><span>Biên bản</span><b>{{ $operation['proof_file']['original_name'] ?? '-' }}</b></p>
                </div>
            </div>

            <div class="alert alert-info mt-3">
                Hệ thống sẽ tạo máy, lưu hồ sơ, gán tài xế và bàn giao theo lựa chọn phía trên.
                Nếu có lỗi, phần dữ liệu database sẽ được hoàn tác.
            </div>

            <div class="wizard-actions">
                <a href="{{ route('machines.wizard.step3') }}" class="btn btn-outline-secondary">← Quay lại chỉnh sửa</a>

                <form method="POST" action="{{ route('machines.wizard.finish') }}"
                      onsubmit="return confirm('Xác nhận tạo máy và ghi dữ liệu vào hệ thống?')">
                    @csrf
                    <button type="submit" class="btn btn-success">Hoàn tất tạo máy</button>
                </form>
            </div>
        @endif
    </div>
</div>

<style>
    .wizard-page { max-width: 1120px; }
    .wizard-progress { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; padding:14px; margin-bottom:22px; }
    .wizard-progress-item { display:flex; align-items:center; gap:10px; padding:8px; color:#6b7280; }
    .wizard-step-number { display:grid; place-items:center; width:38px; height:38px; flex:0 0 38px; border:2px solid #d1d5db; border-radius:50%; background:#fff; font-weight:700; }
    .wizard-progress-item small,.wizard-progress-item strong { display:block; }
    .wizard-progress-item small { font-size:11px; text-transform:uppercase; }
    .wizard-progress-item.is-active .wizard-step-number { border-color:#0d6efd; background:#0d6efd; color:#fff; }
    .wizard-progress-item.is-active strong { color:#0d6efd; }
    .wizard-progress-item.is-done .wizard-step-number { border-color:#198754; background:#198754; color:#fff; }
    .wizard-card { padding:24px; }
    .wizard-card-header { margin-bottom:22px; }
    .wizard-card-header h2 { margin:0 0 6px; font-size:22px; }
    .wizard-card-header p { margin:0; color:#6b7280; }
    .wizard-actions { display:flex; justify-content:space-between; gap:12px; margin-top:24px; padding-top:18px; border-top:1px solid #e5e7eb; }
    .wizard-review-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
    .review-box { border:1px solid #e5e7eb; border-radius:12px; padding:18px; }
    .review-box h3 { margin-bottom:14px; font-size:16px; }
    .review-box p { display:flex; justify-content:space-between; gap:20px; margin:0; padding:7px 0; border-bottom:1px dashed #e5e7eb; }
    .review-box p:last-child { border-bottom:0; }
    .review-box span { color:#6b7280; }
    @media(max-width:768px) {
        .wizard-progress { grid-template-columns:1fr 1fr; }
        .wizard-review-grid { grid-template-columns:1fr; }
        .wizard-actions { flex-direction:column; }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('handover_now');
    const fields = document.querySelectorAll('.handover-field');

    if (!toggle) {
        return;
    }

    function updateHandoverFields() {
        fields.forEach(function (field) {
            field.style.display = toggle.checked ? '' : 'none';
        });
    }

    toggle.addEventListener('change', updateHandoverFields);
    updateHandoverFields();
});
</script>
@endsection
