@extends('layouts.app')

@section('content')
<div class="container-fluid">
    @php
        $statusLabels = [
            'DRAFT' => ['Nháp', 'secondary'],
            'GENERATED' => ['Đã sinh dữ liệu', 'primary'],
            'REVIEWING' => ['Đang duyệt', 'warning'],
            'CONFIRMED' => ['Đã xác nhận', 'success'],
            'EXPORTED' => ['Đã xuất', 'dark'],
        ];
        [$statusLabel, $statusColor] = $statusLabels[$reconciliationPeriod->status] ?? [$reconciliationPeriod->status, 'secondary'];

        $totalRows = (int) $reconciliationPeriod->rows_count;
        $pendingCount = max($totalRows - $reviewedCount, 0);
        $progress = $totalRows > 0 ? round(($reviewedCount / $totalRows) * 100, 1) : 0;
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $reconciliationPeriod->name }}</h1>
            <div class="text-muted small">
                {{ $reconciliationPeriod->date_from->format('d/m/Y') }} – {{ $reconciliationPeriod->date_to->format('d/m/Y') }}
                · {{ $reconciliationPeriod->type === 'WEEKLY' ? 'Theo tuần' : 'Theo tháng' }}
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('reconciliation-periods.index') }}">Danh sách kỳ</a>

            @if (in_array($reconciliationPeriod->status, ['DRAFT', 'GENERATED']))
                <form method="POST"
                      action="{{ route('reconciliation-periods.generate', $reconciliationPeriod) }}"
                      onsubmit="return confirm('Sinh lại dữ liệu sẽ xóa các dòng nháp hiện tại. Tiếp tục?')">
                    @csrf
                    <button class="btn btn-primary" type="submit">
                        {{ $reconciliationPeriod->status === 'GENERATED' ? 'Sinh lại dữ liệu' : 'Sinh dữ liệu' }}
                    </button>
                </form>
            @endif

            @if ($reconciliationPeriod->status === 'GENERATED')
                <form method="POST"
                      action="{{ route('reconciliation-periods.start-review', $reconciliationPeriod) }}"
                      onsubmit="return confirm('Chuyển kỳ này sang trạng thái kiểm tra?')">
                    @csrf
                    <button class="btn btn-warning" type="submit">Bắt đầu kiểm tra</button>
                </form>
            @endif

            @if ($canConfirmPeriod)
                <form method="POST"
                      action="{{ route('reconciliation-periods.confirm', $reconciliationPeriod) }}"
                      onsubmit="return confirm('Xác nhận kỳ đối chiếu này?')">
                    @csrf
                    <button class="btn btn-success" type="submit">Xác nhận kỳ</button>
                </form>
            @endif

            @if ($reconciliationPeriod->status === 'CONFIRMED')
                <form method="POST"
                      action="{{ route('reconciliation-periods.lock', $reconciliationPeriod) }}"
                      onsubmit="return confirm('Khóa kỳ đối chiếu này?')">
                    @csrf
                    <button class="btn btn-dark" type="submit">Khóa kỳ</button>
                </form>
            @endif

            @if ($exportable)
                <a class="btn btn-outline-success" href="{{ route('reconciliation-periods.export', $reconciliationPeriod) }}">Xuất Excel</a>
            @endif

            @if ($reconciliationPeriod->status === 'DRAFT')
                <form method="POST"
                      action="{{ route('reconciliation-periods.delete', $reconciliationPeriod) }}"
                      onsubmit="return confirm('Xóa kỳ đối chiếu nháp này?')">
                    @csrf
                    <button class="btn btn-outline-danger" type="submit">Xóa kỳ nháp</button>
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

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-2">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Tổng dòng</div>
                    <div class="fs-3 fw-bold mt-1">{{ number_format($totalRows) }}</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Đã kiểm tra</div>
                    <div class="fs-3 fw-bold mt-1 text-success">{{ number_format($reviewedCount) }}</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Chưa kiểm tra</div>
                    <div class="fs-3 fw-bold mt-1 text-warning">{{ number_format($pendingCount) }}</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Đã xác nhận</div>
                    <div class="fs-3 fw-bold mt-1 text-primary">{{ number_format($confirmedCount) }}</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Có biến động</div>
                    <div class="fs-3 fw-bold mt-1 text-danger">{{ number_format($changedCount) }}</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-2">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Trạng thái kỳ</div>
                    <div class="mt-2">
                        <span class="badge text-bg-{{ $statusColor }} fs-6">{{ $statusLabel }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-semibold">Tiến độ kiểm tra</div>
                <div class="fw-semibold">{{ number_format($progress, 1) }}%</div>
            </div>
            <div class="progress" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100" style="height: 12px;">
                <div class="progress-bar" style="width: {{ $progress }}%"></div>
            </div>
            <div class="text-muted small mt-2">
                {{ number_format($reviewedCount) }} / {{ number_format($totalRows) }} dòng đã được kiểm tra.
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Tổng hợp trạng thái dòng</div>
                <div class="card-body">
                    @if ($rowSummary->isEmpty())
                        <div class="text-muted">Chưa có dữ liệu đối chiếu.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Trạng thái</th>
                                        <th class="text-end">Số dòng</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rowSummary as $status => $total)
                                        <tr>
                                            <td>{{ $status }}</td>
                                            <td class="text-end fw-semibold">{{ number_format($total) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Biến động trong kỳ</div>
                <div class="card-body">
                    @if ($changeSummary->isEmpty())
                        <div class="text-muted">Chưa ghi nhận biến động.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Loại biến động</th>
                                        <th class="text-end">Số dòng</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($changeSummary as $type => $total)
                                        <tr>
                                            <td>{{ $type }}</td>
                                            <td class="text-end fw-semibold">{{ number_format($total) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-semibold">Danh sách máy theo ngày</div>
                <div class="text-muted small">Hiển thị {{ number_format($rows->total()) }} dòng phù hợp.</div>
            </div>
        </div>

        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('reconciliation-periods.show', $reconciliationPeriod) }}">
                <div class="mb-3">
                    <label class="form-label small">Tìm kiếm</label>
                    <input type="search" name="q" value="{{ request('q') }}" class="form-control" placeholder="Mã máy, lái xe, dự án, BCH, ghi chú...">
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-md-3 col-xl-2">
                        <label class="form-label small">Máy</label>
                        <select name="machine_id" class="form-select">
                            <option value="">Tất cả máy</option>
                            @foreach ($machines as $machine)
                                <option value="{{ $machine->id }}" @selected((string) request('machine_id') === (string) $machine->id)>
                                    {{ $machine->asset_code ?? $machine->code ?? ('Máy #' . $machine->id) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 col-xl-2">
                        <label class="form-label small">BCH</label>
                        <select name="command_center_id" class="form-select">
                            <option value="">Tất cả BCH</option>
                            @foreach ($commandCenters as $commandCenter)
                                <option value="{{ $commandCenter->id }}" @selected((string) request('command_center_id') === (string) $commandCenter->id)>
                                    {{ $commandCenter->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 col-xl-2">
                        <label class="form-label small">Dự án</label>
                        <select name="project_id" class="form-select">
                            <option value="">Tất cả dự án</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>
                                    {{ $project->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 col-xl-2">
                        <label class="form-label small">Ngày</label>
                        <input type="date" name="work_date" value="{{ request('work_date') }}" class="form-control">
                    </div>

                    <div class="col-md-3 col-xl-2">
                        <label class="form-label small">Trạng thái</label>
                        <select name="row_status" class="form-select">
                            <option value="">Tất cả trạng thái</option>
                            @foreach ($rowStatuses as $rowStatus)
                                <option value="{{ $rowStatus }}" @selected(request('row_status') === $rowStatus)>{{ $rowStatus }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 col-xl-2">
                        <label class="form-label small">Biến động</label>
                        <select name="change_type" class="form-select">
                            <option value="">Tất cả biến động</option>
                            @foreach ($changeTypes as $changeType)
                                <option value="{{ $changeType }}" @selected(request('change_type') === $changeType)>{{ $changeType }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary">Lọc dữ liệu</button>
                        <a href="{{ route('reconciliation-periods.show', $reconciliationPeriod) }}" class="btn btn-outline-secondary">Xóa lọc</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ngày</th>
                        <th>Máy</th>
                        <th>BCH</th>
                        <th>Dự án</th>
                        <th>Lái xe</th>
                        <th>Trạng thái</th>
                        <th>Biến động</th>
                        <th>Kiểm tra</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $machineCode = $row->machine?->asset_code ?? $row->machine?->code ?? ('Máy #' . $row->machine_id);
                            $driverName = $row->driver?->name ?? $row->driver?->full_name ?? '—';
                        @endphp
                        <tr>
                            <td class="text-nowrap">{{ $row->work_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="fw-semibold text-nowrap">{{ $machineCode }}</td>
                            <td>{{ $row->commandCenter?->name ?? '—' }}</td>
                            <td>{{ $row->project?->name ?? '—' }}</td>
                            <td>{{ $driverName }}</td>
                            <td>
                                <span class="badge text-bg-{{ $row->status === 'OK' ? 'success' : 'warning' }}">
                                    {{ $row->status ?? '—' }}
                                </span>
                            </td>
                            <td>
                                @if ($row->change_type)
                                    <span class="badge text-bg-danger">{{ $row->change_type }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($row->reviewed_at)
                                    <span class="text-success">Đã kiểm tra</span>
                                    <div class="text-muted small">{{ $row->reviewer?->name ?? '—' }}</div>
                                @else
                                    <span class="text-warning">Chưa kiểm tra</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('reconciliation-rows.show', [$reconciliationPeriod, $row]) }}" class="btn btn-sm btn-outline-primary">
                                    Chi tiết
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-5">
                                Không có dòng đối chiếu phù hợp với bộ lọc.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="card-footer bg-white">
                {{ $rows->links() }}
            </div>
        @endif
    </div>

    @if ($reconciliationPeriod->notes)
        <div class="card shadow-sm border-0 mt-3">
            <div class="card-header bg-white fw-semibold">Ghi chú</div>
            <div class="card-body">{!! nl2br(e($reconciliationPeriod->notes)) !!}</div>
        </div>
    @endif
</div>
@endsection
