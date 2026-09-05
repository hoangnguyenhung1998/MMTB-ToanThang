@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Import máy</h1>
            <a class="btn btn-outline-secondary" href="{{ route('machines.index') }}">Quay lại</a>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('import_errors'))
            <div class="alert alert-danger">
                <p class="fw-semibold mb-2">Có lỗi khi import:</p>
                <ul class="mb-0">
                    @foreach (session('import_errors', []) as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('error_file_url'))
            <div class="mb-3">
                <a class="btn btn-outline-danger" href="{{ session('error_file_url') }}">Tải file lỗi</a>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <p class="mb-3">
                    <a href="{{ route('machines.import.template') }}">Tải file mẫu thêm máy mới</a>
                </p>

                <form method="POST" action="{{ route('machines.import') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">Công ty mặc định cho dòng Excel để trống công ty</label>
                        <select name="company" class="form-select"><option value="">Dùng công ty trong file</option><x-company-options :selected="old('company')" /></select>
                        <a href="{{ route('companies.index') }}">Quản lý công ty</a>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="file">Chọn file Excel (.xlsx)</label>
                        <input class="form-control" type="file" id="file" name="file" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Import máy mới</button>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold">Cập nhật năm sản xuất cho máy cũ</div>
            <div class="card-body">
                <p class="mb-2">File Excel cần có 2 cột: <code>asset_code</code> và <code>manufacture_year</code>.</p>
                <p class="mb-3">
                    <a href="{{ route('machines.import-years.template') }}">Tải file mẫu cập nhật năm sản xuất</a>
                </p>

                <form method="POST" action="{{ route('machines.import-years') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="year_file">Chọn file Excel mã máy + năm sản xuất</label>
                        <input class="form-control" type="file" id="year_file" name="file" accept=".xlsx,.xls" required>
                    </div>
                    <button class="btn btn-success" type="submit">Cập nhật năm sản xuất</button>
                </form>
            </div>
        </div>
    </div>
@endsection
