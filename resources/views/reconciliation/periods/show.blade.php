@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $reconciliationPeriod->name }}</h1>
            <div class="text-muted small">
                {{ $reconciliationPeriod->date_from->format('d/m/Y') }} – {{ $reconciliationPeriod->date_to->format('d/m/Y') }}
                · {{ $reconciliationPeriod->type === 'WEEKLY' ? 'Theo tuần' : 'Theo tháng' }}
            </div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('reconciliation-periods.index') }}">Danh sách kỳ</a>
            @if (in_array($reconciliationPeriod->status, ['DRAFT', 'GENERATED']))
                <form method="POST" action="{{ route('reconciliation-periods.generate', $reconciliationPeriod) }}" onsubmit="return confirm('Sinh lại dữ liệu sẽ xóa các dòng nháp hiện tại. Tiếp tục?')">
                    @csrf
                    <button class="btn btn-primary" type="submit">
                        {{ $reconciliationPeriod->status === 'GENERATED' ? 'Sinh lại dữ liệu' : 'Sinh dữ liệu' }}
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @php
        $statusLabels = [
            'DRAFT' => ['Nháp', 'secondary'],
            'GENERATED' => ['Đã sinh dữ liệu', 'primary'],
            'REVIEWING' => ['Đang duyệt', 'warning'],
            'CONFIRMED' => ['Đã xác nhận', 'success'],
            'EXPORTED' => ['Đã xuất', 'dark'],
        ];
        [$statusLabel, $statusColor] = $statusLabels[$reconciliationPeriod->status] ?? [$reconciliationPeriod->status, 'secondary'];
    @endphp

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Trạng thái</div>
                <div class="mt-2"><span class="badge text-bg-{{ $statusColor }} fs-6">{{ $statusLabel }}</span></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Tổng dòng máy/ngày</div>
                <div class="display-6 fw-semibold">{{ number_format($reconciliationPeriod->rows_count) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Ngày sinh dữ liệu</div>
                <div class="fw-semibold mt-2">{{ $reconciliationPeriod->generated_at?->format('d/m/Y H:i') ?? 'Chưa sinh' }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Người tạo</div>
                <div class="fw-semibold mt-2">{{ $reconciliationPeriod->creator?->name ?? '—' }}</div>
            </div></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header fw-semibold">Tổng hợp trạng thái dòng</div>
                <div class="card-body">
                    @if ($rowSummary->isEmpty())
                        <div class="text-muted">Chưa có dữ liệu. Bấm “Sinh dữ liệu” để hệ thống tạo danh sách máy theo từng ngày.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Trạng thái</th><th class="text-end">Số dòng</th></tr></thead>
                                <tbody>
                                    @foreach ($rowSummary as $status => $total)
                                        <tr><td>{{ $status }}</td><td class="text-end fw-semibold">{{ number_format($total) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header fw-semibold">Biến động trong kỳ</div>
                <div class="card-body">
                    @if ($changeSummary->isEmpty())
                        <div class="text-muted">Chưa ghi nhận biến động.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Loại biến động</th><th class="text-end">Số dòng</th></tr></thead>
                                <tbody>
                                    @foreach ($changeSummary as $type => $total)
                                        <tr><td>{{ $type }}</td><td class="text-end fw-semibold">{{ number_format($total) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($reconciliationPeriod->notes)
        <div class="card mt-3">
            <div class="card-header fw-semibold">Ghi chú</div>
            <div class="card-body">{!! nl2br(e($reconciliationPeriod->notes)) !!}</div>
        </div>
    @endif
</div>
@endsection
