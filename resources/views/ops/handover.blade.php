@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Bàn giao máy: {{ $machine->asset_code }}</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="alert alert-info">Tải ảnh biên bản lên để OCR đọc trước. Hệ thống giữ nguyên ảnh gốc và yêu cầu anh xác nhận trước khi chuyển sang Chờ kích hoạt.</div>
        @php($pendingHandover = $machine->handoverCases()->where('status', '!=', 'HANDED_OVER')->latest()->first())
        @if($pendingHandover)
            <div class="card p-3 mb-3"><div>Máy đang có biên bản OCR chờ xử lý.</div><a class="btn btn-primary mt-2" href="{{ route('machine-handovers.show', $pendingHandover) }}">Mở hồ sơ bàn giao</a></div>
        @else
        <form method="POST" action="{{ route('machine-handovers.store', $machine) }}" enctype="multipart/form-data" class="card p-3">
            @csrf
            <div class="mb-3">
                <label class="form-label">Ảnh biên bản (có thể nhiều trang)</label>
                <input type="file" name="documents[]" class="form-control" accept="image/jpeg,image/png,image/webp,image/bmp,image/tiff" multiple required>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Lưu ảnh và chạy OCR</button>
                <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Hủy</a>
            </div>
        </form>
        @endif
    </div>
@endsection
