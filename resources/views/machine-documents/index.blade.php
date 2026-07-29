@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;
        use Illuminate\Support\Str;
        use Carbon\Carbon;
    @endphp

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="h4 mb-1">Giấy tờ xe: {{ $machine->asset_code }}</h1>
                <p class="text-muted mb-0">Danh sách giấy tờ đã lưu.</p>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-primary" href="{{ route('machine-documents.create', $machine) }}">Thêm giấy tờ</a>
                <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Quay lại</a>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
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
                            $url = $document->file_path ? Storage::disk('public')->url($document->file_path) : null;
                            $isImage = $document->file_path && Str::of($document->file_path)->lower()->endsWith(['.jpg', '.jpeg', '.png', '.webp', '.gif']);
                        @endphp
                        <tr>
                            <td>{{ $document->doc_type }}</td>
                            <td>{{ $document->issued_date ? Carbon::parse($document->issued_date)->format('d/m/Y') : '-' }}</td>
                            <td>{{ $document->expiry_date ? Carbon::parse($document->expiry_date)->format('d/m/Y') : 'Vĩnh viễn' }}</td>
                            <td>{{ $document->note ?? '-' }}</td>
                            <td class="d-flex gap-2 flex-wrap">
                                @if (!$url)
                                    <span class="text-muted">Không có file</span>
                                @elseif ($isImage)
                                    <a class="btn btn-sm btn-outline-primary" href="{{ $url }}" target="_blank">Xem ảnh</a>
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ $url }}" download>Lưu ảnh</a>
                                @else
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ $url }}" download>Tải file</a>
                                @endif
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('machine-documents.edit', [$machine, $document]) }}">Sửa</a>
                                <form method="POST" action="{{ route('machine-documents.delete', [$machine, $document]) }}" class="d-inline" onsubmit="return confirm('Xoá giấy tờ này?')">
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

        <div>
            {{ $documents->links() }}
        </div>
    </div>
@endsection
