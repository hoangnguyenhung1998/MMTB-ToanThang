@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;
        use Illuminate\Support\Str;
        $expiringTypes = ['Thẻ ATLĐ', 'Giấy khám sức khỏe', 'Bảo hiểm tai nạn'];
    @endphp

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Chi tiết tài xế: {{ $driver->name }}</h1>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-primary" href="{{ route('driver-documents.create', $driver) }}">Thêm giấy tờ</a>
                <a class="btn btn-outline-secondary" href="{{ route('drivers.edit', $driver) }}">Sửa thông tin</a>
            </div>
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

        <div class="card mb-3">
            <div class="card-header">Thông tin tài xế</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Họ tên:</strong> {{ $driver->name }}</div>
                    <div class="col-md-4"><strong>SĐT:</strong> {{ $driver->phone ?? '-' }}</div>
                    <div class="col-md-4"><strong>Số CCCD:</strong> {{ $driver->cccd_no ?? '-' }}</div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Giấy tờ tài xế</span>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('driver-documents.index', $driver) }}">Xem tất cả</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Loại</th>
                            <th>Ngày cấp</th>
                            <th>Ngày hết hạn</th>
                            <th>Ghi chú</th>
                            <th>File</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $document)
                            @php
                                $url = Storage::disk('public')->url($document->file_path);
                                $isImage = Str::of($document->file_path)->lower()->endsWith(['.jpg', '.jpeg', '.png', '.webp', '.gif']);
                            @endphp
                            <tr>
                                <td>
                                    {{ $document->doc_type }}
                                    @if (in_array($document->doc_type, $expiringTypes, true))
                                        <span class="badge bg-warning text-dark">Có hạn</span>
                                    @endif
                                </td>
                                <td>{{ $document->issued_date ?? '-' }}</td>
                                <td>{{ $document->expiry_date ?? '-' }}</td>
                                <td>{{ $document->note ?? '-' }}</td>
                                <td>
                                    @if ($isImage)
                                        <img src="{{ $url }}" alt="preview" style="max-height: 60px;">
                                    @else
                                        <a href="{{ $url }}" download>Tải file</a>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('driver-documents.edit', [$driver, $document]) }}">Sửa</a>
                                    <form method="POST" action="{{ route('driver-documents.delete', [$driver, $document]) }}" class="d-inline" onsubmit="return confirm('Xoá giấy tờ này?')">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Xoá</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted">Chưa có giấy tờ.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Xe đã từng gán</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Mã máy</th>
                            <th>Bắt đầu</th>
                            <th>Kết thúc</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($machineHistory as $history)
                            <tr>
                                <td>{{ $history->machine?->asset_code ?? '-' }}</td>
                                <td>{{ $history->started_at }}</td>
                                <td>{{ $history->ended_at ?? '-' }}</td>
                                <td class="text-end">
                                    @if ($history->machine)
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('machines.show', $history->machine) }}">Xem máy</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted">Chưa có lịch sử gán.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
