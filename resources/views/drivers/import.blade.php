@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Import tài xế</h1>
            <a class="btn btn-outline-secondary" href="{{ route('drivers.index') }}">Quay lại</a>
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
                    <a href="{{ route('drivers.import.template') }}">Tải file mẫu</a>
                </p>

                <form method="POST" action="{{ route('drivers.import') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="file">Chọn file Excel (.xlsx)</label>
                        <input class="form-control" type="file" id="file" name="file" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Import</button>
                </form>
            </div>
        </div>
    </div>
@endsection
