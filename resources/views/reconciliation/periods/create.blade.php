@extends('layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 920px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Tạo kỳ đối chiếu</h1>
            <div class="text-muted small">Tạo khung thời gian trước, sau đó hệ thống sẽ sinh dữ liệu máy theo lịch sử.</div>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('reconciliation-periods.index') }}">Quay lại</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('reconciliation-periods.store') }}" class="card">
        @csrf
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Tên kỳ <span class="text-danger">*</span></label>
                <input class="form-control" type="text" name="name" value="{{ old('name') }}" placeholder="Ví dụ: Đối chiếu tháng 07/2026" required>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Loại kỳ <span class="text-danger">*</span></label>
                    <select class="form-select" name="type" required>
                        <option value="MONTHLY" @selected(old('type', 'MONTHLY') === 'MONTHLY')>Theo tháng</option>
                        <option value="WEEKLY" @selected(old('type') === 'WEEKLY')>Theo tuần</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Từ ngày <span class="text-danger">*</span></label>
                    <input class="form-control" type="date" name="date_from" value="{{ old('date_from') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Đến ngày <span class="text-danger">*</span></label>
                    <input class="form-control" type="date" name="date_to" value="{{ old('date_to') }}" required>
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label">Ghi chú</label>
                <textarea class="form-control" name="notes" rows="4" placeholder="Thông tin cần lưu ý cho kỳ đối chiếu...">{{ old('notes') }}</textarea>
            </div>

            <div class="alert alert-info mt-3 mb-0">
                Sau khi lưu, kỳ ở trạng thái <strong>Nháp</strong>. Dữ liệu chỉ được sinh khi anh bấm nút <strong>Sinh dữ liệu</strong> ở màn hình chi tiết.
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('reconciliation-periods.index') }}">Hủy</a>
            <button class="btn btn-primary" type="submit">Tạo kỳ</button>
        </div>
    </form>
</div>
@endsection
