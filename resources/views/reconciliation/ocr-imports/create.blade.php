@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col">
                <h1 class="h3 mb-0">Nhập dữ liệu OCR</h1>
            </div>
            <div class="col-auto">
                <a href="{{ route('reconciliation-periods.show', $reconciliationPeriod) }}" class="btn btn-outline-secondary">
                    Hủy nhập
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <div class="fw-semibold mb-1">Không thể tiếp tục nhập dữ liệu OCR.</div>
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header">
                Thông tin kỳ đối soát
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Tên kỳ</dt>
                    <dd class="col-sm-9">{{ $reconciliationPeriod->name }}</dd>

                    <dt class="col-sm-3">Khoảng ngày</dt>
                    <dd class="col-sm-9">
                        {{ \Carbon\Carbon::parse($reconciliationPeriod->date_from)->format('d/m/Y') }}
                        -
                        {{ \Carbon\Carbon::parse($reconciliationPeriod->date_to)->format('d/m/Y') }}
                    </dd>

                    <dt class="col-sm-3">Trạng thái</dt>
                    <dd class="col-sm-9">{{ $reconciliationPeriod->status }}</dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                Chọn file OCR
            </div>
            <form method="POST" action="{{ route('reconciliation-periods.ocr-import.preview', $reconciliationPeriod) }}" enctype="multipart/form-data">
                @csrf

                <div class="card-body">
                    <div class="mb-3">
                        <label for="ocr_file" class="form-label">File OCR</label>
                        <input
                            id="ocr_file"
                            name="ocr_file"
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            required
                            class="form-control @error('ocr_file') is-invalid @enderror"
                        >
                        @error('ocr_file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            File OCR hợp lệ phải có sheet Sheet1 theo mẫu ứng dụng OCR. Chấp nhận file .xlsx, .xls hoặc .csv.
                        </div>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-end gap-2">
                    <a href="{{ route('reconciliation-periods.show', $reconciliationPeriod) }}" class="btn btn-outline-secondary">
                        Hủy nhập
                    </a>
                    <button type="submit" class="btn btn-primary">
                        Xem trước dữ liệu
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
