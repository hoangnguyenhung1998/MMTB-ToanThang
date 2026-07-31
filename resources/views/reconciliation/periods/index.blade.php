@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Kỳ đối chiếu</h1>
            <div class="text-muted small">Quản lý các kỳ tuần/tháng và sinh dữ liệu máy theo lịch sử thực tế.</div>
        </div>
        <a class="btn btn-primary" href="{{ route('reconciliation-periods.create') }}">Tạo kỳ đối chiếu</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Loại kỳ</label>
                <select class="form-select" name="type">
                    <option value="">Tất cả</option>
                    <option value="WEEKLY" @selected(request('type') === 'WEEKLY')>Theo tuần</option>
                    <option value="MONTHLY" @selected(request('type') === 'MONTHLY')>Theo tháng</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Trạng thái</label>
                <select class="form-select" name="status">
                    <option value="">Tất cả</option>
                    @foreach (['DRAFT' => 'Nháp', 'GENERATED' => 'Đã sinh dữ liệu', 'REVIEWING' => 'Đang duyệt', 'CONFIRMED' => 'Đã xác nhận', 'EXPORTED' => 'Đã xuất'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-auto">
                <button class="btn btn-outline-primary" type="submit">Lọc</button>
                <a class="btn btn-outline-secondary" href="{{ route('reconciliation-periods.index') }}">Xóa lọc</a>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tên kỳ</th>
                        <th>Loại</th>
                        <th>Thời gian</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Số dòng</th>
                        <th>Người tạo</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($periods as $period)
                        @php
                            $statusLabels = [
                                'DRAFT' => ['Nháp', 'secondary'],
                                'GENERATED' => ['Đã sinh dữ liệu', 'primary'],
                                'REVIEWING' => ['Đang duyệt', 'warning'],
                                'CONFIRMED' => ['Đã xác nhận', 'success'],
                                'EXPORTED' => ['Đã xuất', 'dark'],
                            ];
                            [$statusLabel, $statusColor] = $statusLabels[$period->status] ?? [$period->status, 'secondary'];
                        @endphp
                        <tr>
                            <td class="fw-semibold">{{ $period->name }}</td>
                            <td>{{ $period->type === 'WEEKLY' ? 'Tuần' : 'Tháng' }}</td>
                            <td>{{ $period->date_from->format('d/m/Y') }} – {{ $period->date_to->format('d/m/Y') }}</td>
                            <td><span class="badge text-bg-{{ $statusColor }}">{{ $statusLabel }}</span></td>
                            <td class="text-end">{{ number_format($period->rows_count) }}</td>
                            <td>{{ $period->creator?->name ?? '—' }}</td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('reconciliation-periods.show', $period) }}">Mở</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">Chưa có kỳ đối chiếu.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($periods->hasPages())
            <div class="card-footer">{{ $periods->links() }}</div>
        @endif
    </div>
</div>
@endsection
