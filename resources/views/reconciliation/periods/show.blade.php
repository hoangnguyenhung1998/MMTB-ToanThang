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
        $isDraftExport = in_array($reconciliationPeriod->status, ['GENERATED', 'REVIEWING'], true);
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

            @if (in_array($reconciliationPeriod->status, ['GENERATED', 'REVIEWING']))
                <form method="POST"
                      action="{{ route('reconciliation-periods.allocate-times', $reconciliationPeriod) }}"
                      onsubmit="return confirm('Tự phân bổ lại giờ từ các nhật trình đã duyệt? Các dòng chưa xác nhận sẽ trở về nháp.')">
                    @csrf
                    <button class="btn btn-outline-primary" type="submit">Tự phân bổ 7 giờ</button>
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
                <form method="GET" action="{{ route('reconciliation-periods.export', $reconciliationPeriod) }}">
                    <input type="hidden" name="mode" value="workbook">
                    @foreach (['machine_id', 'project_id', 'command_center_id', 'date_from', 'date_to'] as $exportFilter)
                        @if (request($exportFilter))
                            <input type="hidden" name="{{ $exportFilter }}" value="{{ request($exportFilter) }}">
                        @endif
                    @endforeach
                    @if ($exportValidation['warnings']->isNotEmpty())
                        <input type="hidden" name="acknowledge_warnings" value="1">
                    @endif
                    <button class="btn btn-outline-success" type="submit"
                            @disabled(!$exportValidation['can_export'])
                            title="{{ $exportValidation['can_export'] ? '' : 'Kỳ còn lỗi bắt buộc phải sửa trước khi xuất' }}"
                            @if ($exportValidation['warnings']->isNotEmpty())
                                onclick="return confirm('Kỳ này còn cảnh báo. Anh đã kiểm tra và vẫn muốn xuất?')"
                            @endif>
                        {{ $isDraftExport ? 'Xuất Excel nháp' : 'Xuất Excel BCH' }}
                    </button>
                </form>
                <form method="GET" action="{{ route('reconciliation-periods.export', $reconciliationPeriod) }}">
                    <input type="hidden" name="mode" value="zip">
                    @foreach (['machine_id', 'project_id', 'command_center_id', 'date_from', 'date_to'] as $exportFilter)
                        @if (request($exportFilter))
                            <input type="hidden" name="{{ $exportFilter }}" value="{{ request($exportFilter) }}">
                        @endif
                    @endforeach
                    @if ($exportValidation['warnings']->isNotEmpty())
                        <input type="hidden" name="acknowledge_warnings" value="1">
                    @endif
                    <button class="btn btn-success" type="submit"
                            @disabled(!$exportValidation['can_export'])
                            title="{{ $exportValidation['can_export'] ? '' : 'Kỳ còn lỗi bắt buộc phải sửa trước khi xuất' }}"
                            @if ($exportValidation['warnings']->isNotEmpty())
                                onclick="return confirm('Kỳ này còn cảnh báo. Anh đã kiểm tra và vẫn muốn xuất từng BCH?')"
                            @endif>
                        {{ $isDraftExport ? 'ZIP nháp từng BCH' : 'Tải ZIP từng BCH' }}
                    </button>
                </form>
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

    @if ($exportValidation['blocking']->isNotEmpty() || $exportValidation['warnings']->isNotEmpty())
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold">Kiểm tra trước khi xuất Excel</div>
            <div class="card-body">
                @if ($exportValidation['blocking']->isNotEmpty())
                    <div class="alert alert-danger mb-3">
                        <div class="fw-semibold mb-2">Có {{ $exportValidation['blocking']->count() }} lỗi đang chặn xuất:</div>
                        <ul class="mb-0">
                            @foreach ($exportValidation['blocking']->take(20) as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($exportValidation['warnings']->isNotEmpty())
                    <div class="alert alert-warning mb-0">
                        <div class="fw-semibold mb-2">Có {{ $exportValidation['warnings']->count() }} cảnh báo cần kiểm tra:</div>
                        <ul class="mb-0">
                            @foreach ($exportValidation['warnings']->take(20) as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
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
                <div class="text-muted small">
                    Máy {{ $machinePager->first()?->machine_code ?? '—' }}
                    · {{ number_format($rows->count()) }} dòng
                    · trang {{ $machinePager->currentPage() }}/{{ max(1, $machinePager->lastPage()) }} máy
                    · ngày tăng dần.
                </div>
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
                        <label class="form-label small">Từ ngày</label>
                        <input type="date" name="date_from" value="{{ request('date_from') }}"
                               min="{{ $reconciliationPeriod->date_from->toDateString() }}"
                               max="{{ $reconciliationPeriod->date_to->toDateString() }}" class="form-control">
                    </div>

                    <div class="col-md-3 col-xl-2">
                        <label class="form-label small">Đến ngày</label>
                        <input type="date" name="date_to" value="{{ request('date_to') }}"
                               min="{{ $reconciliationPeriod->date_from->toDateString() }}"
                               max="{{ $reconciliationPeriod->date_to->toDateString() }}" class="form-control">
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
                    <div class="col-12 d-flex flex-wrap align-items-center gap-2 mt-2">
                        <span class="small text-muted">Phạm vi nhanh:</span>
                        @foreach ([[1, 7], [8, 14], [15, 21], [22, $reconciliationPeriod->date_to->day]] as [$fromDay, $toDay])
                            @php
                                $quickFrom = $reconciliationPeriod->date_from->copy()->day($fromDay)->toDateString();
                                $quickTo = $reconciliationPeriod->date_from->copy()->day($toDay)->toDateString();
                                $quickQuery = [
                                    ...request()->except(['date_from', 'date_to', 'work_date', 'machine_page']),
                                    'date_from' => $quickFrom,
                                    'date_to' => $quickTo,
                                ];
                            @endphp
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('reconciliation-periods.show', ['reconciliationPeriod' => $reconciliationPeriod, ...$quickQuery]) }}">
                                {{ str_pad($fromDay, 2, '0', STR_PAD_LEFT) }}–{{ str_pad($toDay, 2, '0', STR_PAD_LEFT) }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </form>
        </div>

        <div class="table-responsive reconciliation-grid">
            <table class="table table-bordered table-hover align-middle mb-0 text-nowrap">
                <thead class="table-light text-center align-middle">
                    <tr>
                        <th rowspan="2" class="sticky-col">Ngày</th>
                        <th rowspan="2">Máy</th>
                        @if (!request('command_center_id'))<th rowspan="2">BCH</th>@endif
                        <th colspan="3" class="table-primary">Định vị</th>
                        <th colspan="4" class="table-success">Hành chính</th>
                        <th colspan="6" class="table-warning">Tăng ca</th>
                        <th rowspan="2">Tổng NT</th>
                        <th rowspan="2">Chênh lệch</th>
                        <th rowspan="2">Vị trí</th>
                        <th rowspan="2">Lỗi giải trình</th>
                        <th rowspan="2">Công việc</th>
                        <th rowspan="2">Trạng thái</th>
                        <th rowspan="2">Chi tiết</th>
                    </tr>
                    <tr>
                        <th>Bắt đầu</th><th>Kết thúc</th><th>Tổng</th>
                        <th>Sáng BĐ</th><th>Sáng KT</th><th>Chiều BĐ</th><th>Chiều KT</th>
                        <th>Trưa BĐ</th><th>Trưa KT</th><th>Chiều BĐ</th><th>Chiều KT</th><th>Tối BĐ</th><th>Tối KT</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $machineCode = $row->machine?->asset_code ?? $row->machine?->code ?? ('Máy #' . $row->machine_id);
                            $calculation = $rowCalculations[$row->id] ?? [];
                            $fmtTime = fn ($value) => $value ? substr((string) $value, 0, 5) : '—';
                            $fmtMinutes = fn ($minutes) => $minutes === null ? '—' : sprintf('%d:%02d', intdiv(abs((int) $minutes), 60), abs((int) $minutes) % 60);
                            $difference = $calculation['difference_minutes'] ?? null;
                            $differenceClass = match ($calculation['variance'] ?? null) {
                                'MATCHED', 'MINOR' => 'text-success',
                                'REVIEW_REQUIRED' => 'text-warning',
                                'ABNORMAL' => 'text-danger',
                                default => 'text-muted',
                            };
                            $canEditRow = in_array($row->status, ['DRAFT', 'REJECTED', 'REVIEWED'], true);
                            $rowFormId = 'row-form-'.$row->id;
                        @endphp
                        <tr>
                            <td class="sticky-col bg-white">{{ $row->work_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="fw-semibold">{{ $machineCode }}</td>
                            @if (!request('command_center_id'))<td>{{ $row->commandCenter?->name ?? '—' }}</td>@endif
                            <td>{{ $fmtTime($row->gps_check_in) }}</td>
                            <td>{{ $fmtTime($row->gps_check_out) }}</td>
                            <td class="fw-semibold">{{ $fmtMinutes($calculation['gps_minutes'] ?? null) }}</td>
                            @foreach (['regular_morning_start', 'regular_morning_end', 'regular_afternoon_start', 'regular_afternoon_end', 'overtime_lunch_start', 'overtime_lunch_end', 'overtime_afternoon_start', 'overtime_afternoon_end', 'overtime_evening_start', 'overtime_evening_end'] as $timeField)
                                <td>
                                    @if ($canEditRow)
                                        <input class="form-control form-control-sm grid-time-input" type="text" inputmode="numeric"
                                               name="{{ $timeField }}" form="{{ $rowFormId }}"
                                               maxlength="5" pattern="(?:[01]?\d|2[0-3])(?::[0-5]\d)?" placeholder="7 hoặc 07:00"
                                               title="Có thể nhập 7, 7:00 hoặc 07:00; để trống nếu nghỉ"
                                               value="{{ $row->{$timeField} ? substr((string) $row->{$timeField}, 0, 5) : '' }}"
                                               aria-label="{{ $timeField }}">
                                    @else
                                        {{ $fmtTime($row->{$timeField}) }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="fw-semibold">{{ $fmtMinutes($calculation['logbook_minutes'] ?? null) }}</td>
                            <td class="fw-semibold {{ $differenceClass }}">
                                {{ $difference === null ? '—' : (($difference > 0 ? '+' : ($difference < 0 ? '−' : '')) . $fmtMinutes($difference)) }}
                            </td>
                            @foreach (['work_location', 'explanation', 'work_content'] as $textField)
                                <td>
                                    @if ($canEditRow)
                                        <input class="form-control form-control-sm grid-text-input" type="text"
                                               name="{{ $textField }}" form="{{ $rowFormId }}"
                                               value="{{ $row->{$textField} }}"
                                               aria-label="{{ $textField }}">
                                    @else
                                        <div class="text-truncate grid-text-value" title="{{ $row->{$textField} }}">{{ $row->{$textField} ?: '—' }}</div>
                                    @endif
                                </td>
                            @endforeach
                            <td><span class="badge text-bg-{{ $row->status === 'CONFIRMED' ? 'success' : ($row->status === 'REJECTED' ? 'danger' : 'warning') }}">{{ $row->status }}</span></td>
                            <td class="d-flex gap-1">
                                @if ($canEditRow)
                                    <form id="{{ $rowFormId }}" method="POST" action="{{ route('reconciliation-rows.update', [$reconciliationPeriod, $row]) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="return_to" value="period">
                                        <input type="hidden" name="machine_page" value="{{ $machinePager->currentPage() }}">
                                        @foreach (request()->only(['q', 'machine_id', 'project_id', 'command_center_id', 'work_date', 'date_from', 'date_to', 'row_status', 'change_type']) as $filterName => $filterValue)
                                            @if ($filterValue !== null && $filterValue !== '')
                                                <input type="hidden" name="return_filters[{{ $filterName }}]" value="{{ $filterValue }}">
                                            @endif
                                        @endforeach
                                        <button class="btn btn-sm btn-primary" type="submit" name="submit_action" value="save">Lưu</button>
                                        @if ($reconciliationPeriod->status === 'REVIEWING')
                                            <button class="btn btn-sm btn-success" type="submit" name="submit_action" value="quick_confirm"
                                                    onclick="return confirm('Lưu dữ liệu và xác nhận dòng này?')">
                                                Lưu & xác nhận
                                            </button>
                                        @endif
                                    </form>
                                @endif
                                <a href="{{ route('reconciliation-rows.show', [$reconciliationPeriod, $row]) }}" class="btn btn-sm btn-outline-primary">Chi tiết</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="25" class="text-center text-muted py-5">
                                Không có dòng đối chiếu phù hợp với bộ lọc.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <style>
            .reconciliation-grid { max-height: 68vh; }
            .reconciliation-grid thead { position: sticky; top: 0; z-index: 3; }
            .reconciliation-grid th, .reconciliation-grid td { font-size: .78rem; padding: .5rem .55rem; }
            .reconciliation-grid .grid-time-input { min-width: 92px; padding: .25rem .35rem; font-size: .78rem; }
            .reconciliation-grid .grid-text-input { min-width: 170px; padding: .25rem .35rem; font-size: .78rem; }
            .reconciliation-grid .grid-text-value { max-width: 190px; }
            .reconciliation-grid .sticky-col { position: sticky; left: 0; z-index: 2; min-width: 92px; }
            .reconciliation-grid thead .sticky-col { z-index: 4; }
        </style>

        @if ($machinePager->hasPages())
            <div class="card-footer bg-white">
                {{ $machinePager->links() }}
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
