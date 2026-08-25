@extends('layouts.app')

@section('content')
@php
    $periodStatusLabels = [
        'DRAFT' => ['Nháp', 'secondary'],
        'GENERATED' => ['Đã sinh dữ liệu', 'primary'],
        'REVIEWING' => ['Đang kiểm tra', 'warning'],
        'CONFIRMED' => ['Đã xác nhận', 'success'],
        'EXPORTED' => ['Đã xuất', 'dark'],
    ];

    $rowStatusLabels = [
        'DRAFT' => ['Nháp', 'secondary'],
        'REVIEWED' => ['Đã kiểm tra', 'success'],
        'CONFIRMED' => ['Đã xác nhận', 'primary'],
        'REJECTED' => ['Từ chối', 'danger'],
    ];

    [$periodStatusLabel, $periodStatusColor] = $periodStatusLabels[$reconciliationPeriod->status] ?? [$reconciliationPeriod->status, 'secondary'];
    [$rowStatusLabel, $rowStatusColor] = $rowStatusLabels[$reconciliationRow->status] ?? [$reconciliationRow->status, 'secondary'];

    $machineCode = $reconciliationRow->machine?->asset_code
        ?? $reconciliationRow->machine?->code
        ?? ('Máy #' . $reconciliationRow->machine_id);

    $driverName = $reconciliationRow->driver?->name
        ?? $reconciliationRow->driver?->full_name
        ?? '—';

    $formatTime = fn ($value) => $value ? substr((string) $value, 0, 5) : '—';
    $formatMinutes = fn ($value) => $value === null ? '—' : number_format((int) $value) . ' phút';
    $canEdit = in_array($reconciliationRow->status, ['DRAFT', 'REJECTED', 'REVIEWED'], true);
    $canReview = in_array($reconciliationRow->status, ['DRAFT', 'REJECTED'], true);
    $canConfirm = $reconciliationRow->status === 'REVIEWED';
@endphp

<div class="container-fluid">
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
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

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Chi tiết đối chiếu {{ $machineCode }}</h1>
            <div class="text-muted small">
                {{ $reconciliationRow->work_date?->format('d/m/Y') ?? '—' }}
                · {{ $reconciliationPeriod->name }}
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('reconciliation-periods.show', $reconciliationPeriod) }}">Quay lại kỳ</a>
            <span class="badge text-bg-{{ $rowStatusColor }} align-self-center fs-6">{{ $rowStatusLabel }}</span>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Thông tin kỳ</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Tên kỳ</dt>
                        <dd class="col-7 fw-semibold">{{ $reconciliationPeriod->name }}</dd>
                        <dt class="col-5 text-muted">Thời gian</dt>
                        <dd class="col-7">{{ $reconciliationPeriod->date_from?->format('d/m/Y') ?? '—' }} - {{ $reconciliationPeriod->date_to?->format('d/m/Y') ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Loại kỳ</dt>
                        <dd class="col-7">{{ $reconciliationPeriod->type === 'WEEKLY' ? 'Theo tuần' : 'Theo tháng' }}</dd>
                        <dt class="col-5 text-muted">Trạng thái</dt>
                        <dd class="col-7"><span class="badge text-bg-{{ $periodStatusColor }}">{{ $periodStatusLabel }}</span></dd>
                        <dt class="col-5 text-muted">Người tạo</dt>
                        <dd class="col-7">{{ $reconciliationRow->period?->creator?->name ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Máy và phân công</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Mã máy</dt>
                        <dd class="col-7 fw-semibold">{{ $machineCode }}</dd>
                        <dt class="col-5 text-muted">BCH</dt>
                        <dd class="col-7">{{ $reconciliationRow->commandCenter?->name ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Dự án</dt>
                        <dd class="col-7">{{ $reconciliationRow->project?->name ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Lái xe</dt>
                        <dd class="col-7">{{ $driverName }}</dd>
                        <dt class="col-5 text-muted">Assignment</dt>
                        <dd class="col-7">#{{ $reconciliationRow->machine_assignment_id ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Kiểm tra và xác nhận</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Trạng thái dòng</dt>
                        <dd class="col-7"><span class="badge text-bg-{{ $rowStatusColor }}">{{ $rowStatusLabel }}</span></dd>
                        <dt class="col-5 text-muted">Người kiểm tra</dt>
                        <dd class="col-7">{{ $reconciliationRow->reviewer?->name ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Thời điểm kiểm tra</dt>
                        <dd class="col-7">{{ $reconciliationRow->reviewed_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Người xác nhận</dt>
                        <dd class="col-7">{{ $reconciliationRow->confirmer?->name ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Thời điểm xác nhận</dt>
                        <dd class="col-7">{{ $reconciliationRow->confirmed_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Logbook / OCR</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-7 text-muted">OCR vào thô</dt>
                        <dd class="col-5 text-end">{{ $formatTime($reconciliationRow->ocr_check_in_raw) }}</dd>
                        <dt class="col-7 text-muted">OCR ra thô</dt>
                        <dd class="col-5 text-end">{{ $formatTime($reconciliationRow->ocr_check_out_raw) }}</dd>
                        <dt class="col-7 text-muted">Giờ vào xác nhận</dt>
                        <dd class="col-5 text-end">{{ $formatTime($reconciliationRow->confirmed_check_in) }}</dd>
                        <dt class="col-7 text-muted">Giờ ra xác nhận</dt>
                        <dd class="col-5 text-end">{{ $formatTime($reconciliationRow->confirmed_check_out) }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Định vị</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-7 text-muted">Bắt đầu</dt>
                        <dd class="col-5 text-end">{{ $formatTime($reconciliationRow->gps_check_in) }}</dd>
                        <dt class="col-7 text-muted">Kết thúc</dt>
                        <dd class="col-5 text-end">{{ $formatTime($reconciliationRow->gps_check_out) }}</dd>
                        <dt class="col-7 text-muted">Tổng</dt>
                        <dd class="col-5 text-end fw-semibold">{{ $formatMinutes($calculation['gps_minutes']) }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Chênh lệch</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-7 text-muted">Logbook</dt>
                        <dd class="col-5 text-end">{{ $formatMinutes($calculation['logbook_minutes']) }}</dd>
                        <dt class="col-7 text-muted">GPS</dt>
                        <dd class="col-5 text-end">{{ $formatMinutes($calculation['gps_minutes']) }}</dd>
                        <dt class="col-7 text-muted">Chênh lệch</dt>
                        <dd class="col-5 text-end fw-semibold">{{ $formatMinutes($calculation['difference_minutes']) }}</dd>
                        <dt class="col-7 text-muted">Mức lệch</dt>
                        <dd class="col-5 text-end">{{ $calculation['variance'] ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    @if ($canEdit)
        <form method="POST" action="{{ route('reconciliation-rows.update', [$reconciliationPeriod, $reconciliationRow]) }}" class="card shadow-sm border-0 mb-3">
            @csrf
            @method('PUT')
            <div class="card-header bg-white">
                <div class="fw-semibold">Cập nhật dữ liệu đối chiếu</div>
                <div class="small text-muted">Hệ thống tự tính tổng phút từ các cặp giờ. Tối đa 7 giờ được tính hành chính; phần còn lại để ở tăng ca.</div>
            </div>
            <div class="card-body">
                @php
                    $timeGroups = [
                        'Hành chính ca sáng' => ['regular_morning_start', 'regular_morning_end'],
                        'Hành chính ca chiều' => ['regular_afternoon_start', 'regular_afternoon_end'],
                        'Tăng ca trưa' => ['overtime_lunch_start', 'overtime_lunch_end'],
                        'Tăng ca chiều' => ['overtime_afternoon_start', 'overtime_afternoon_end'],
                        'Tăng ca tối' => ['overtime_evening_start', 'overtime_evening_end'],
                    ];
                @endphp
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">OCR vào thô</label>
                        <input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]?\d|2[0-3])(?::[0-5]\d)?" placeholder="7 hoặc 07:00" title="Có thể nhập 7, 7:00 hoặc 07:00; để trống nếu nghỉ" name="ocr_check_in_raw" value="{{ old('ocr_check_in_raw', $reconciliationRow->ocr_check_in_raw ? substr((string) $reconciliationRow->ocr_check_in_raw, 0, 5) : '') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">OCR ra thô</label>
                        <input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]?\d|2[0-3])(?::[0-5]\d)?" placeholder="18 hoặc 18:00" title="Có thể nhập 18 hoặc 18:00; để trống nếu nghỉ" name="ocr_check_out_raw" value="{{ old('ocr_check_out_raw', $reconciliationRow->ocr_check_out_raw ? substr((string) $reconciliationRow->ocr_check_out_raw, 0, 5) : '') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Định vị bắt đầu</label>
                        <input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]?\d|2[0-3])(?::[0-5]\d)?" placeholder="7 hoặc 07:00" title="Có thể nhập 7, 7:00 hoặc 07:00; để trống nếu nghỉ" name="gps_check_in" value="{{ old('gps_check_in', $reconciliationRow->gps_check_in ? substr((string) $reconciliationRow->gps_check_in, 0, 5) : '') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Định vị kết thúc</label>
                        <input class="form-control" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]?\d|2[0-3])(?::[0-5]\d)?" placeholder="18 hoặc 18:00" title="Có thể nhập 18 hoặc 18:00; để trống nếu nghỉ" name="gps_check_out" value="{{ old('gps_check_out', $reconciliationRow->gps_check_out ? substr((string) $reconciliationRow->gps_check_out, 0, 5) : '') }}">
                    </div>
                    <div class="col-12"><hr class="my-1"><div class="fw-semibold">Giờ nhật trình xác nhận</div></div>
                    @foreach ($timeGroups as $label => [$startField, $endField])
                        <div class="col-lg col-md-4">
                            <div class="border rounded p-3 h-100 bg-light">
                                <div class="fw-semibold small mb-2">{{ $label }}</div>
                                <label class="form-label small">Bắt đầu</label>
                                <input class="form-control form-control-sm mb-2" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]?\d|2[0-3])(?::[0-5]\d)?" placeholder="7 hoặc 07:00" title="Có thể nhập 7, 7:00 hoặc 07:00; để trống nếu nghỉ" name="{{ $startField }}" value="{{ old($startField, $reconciliationRow->{$startField} ? substr((string) $reconciliationRow->{$startField}, 0, 5) : '') }}">
                                <label class="form-label small">Kết thúc</label>
                                <input class="form-control form-control-sm" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]?\d|2[0-3])(?::[0-5]\d)?" placeholder="18 hoặc 18:00" title="Có thể nhập 18 hoặc 18:00; để trống nếu nghỉ" name="{{ $endField }}" value="{{ old($endField, $reconciliationRow->{$endField} ? substr((string) $reconciliationRow->{$endField}, 0, 5) : '') }}">
                            </div>
                        </div>
                    @endforeach
                    <div class="col-md-6">
                        <label class="form-label">Địa điểm</label>
                        <input class="form-control" name="work_location" value="{{ old('work_location', $reconciliationRow->work_location) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Ghi chú</label>
                        <input class="form-control" name="notes" value="{{ old('notes', $reconciliationRow->notes) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nội dung công việc</label>
                        <textarea class="form-control" name="work_content" rows="4">{{ old('work_content', $reconciliationRow->work_content) }}</textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Giải trình</label>
                        <textarea class="form-control" name="explanation" rows="4">{{ old('explanation', $reconciliationRow->explanation) }}</textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary" type="submit">Lưu và đưa về nháp</button>
            </div>
        </form>
    @endif

    <div class="row g-3 mb-3">
        @if ($canReview)
            <div class="col-lg-6">
                <form method="POST" action="{{ route('reconciliation-rows.review', [$reconciliationPeriod, $reconciliationRow]) }}" class="card h-100 shadow-sm border-0">
                    @csrf
                    <div class="card-header bg-white fw-semibold">Kiểm tra dòng</div>
                    <div class="card-body">
                        <label class="form-label">Kết quả</label>
                        <select class="form-select mb-3" name="decision" required>
                            <option value="accept">Chấp nhận</option>
                            <option value="reject">Từ chối</option>
                        </select>
                        <label class="form-label">Nhận xét</label>
                        <textarea class="form-control" name="comment" rows="4">{{ old('comment') }}</textarea>
                    </div>
                    <div class="card-footer bg-white text-end">
                        <button class="btn btn-success" type="submit">Lưu kết quả kiểm tra</button>
                    </div>
                </form>
            </div>
        @endif

        @if ($canConfirm)
            <div class="col-lg-6">
                <form method="POST" action="{{ route('reconciliation-rows.confirm', [$reconciliationPeriod, $reconciliationRow]) }}" class="card h-100 shadow-sm border-0">
                    @csrf
                    <div class="card-header bg-white fw-semibold">Xác nhận dòng</div>
                    <div class="card-body text-muted">Chỉ dòng đã kiểm tra mới được xác nhận.</div>
                    <div class="card-footer bg-white text-end">
                        <button class="btn btn-primary" type="submit">Xác nhận dòng</button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold">Ghi chú công việc</div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-md-3 text-muted">Địa điểm làm việc</dt>
                <dd class="col-md-9">{{ $reconciliationRow->work_location ?: '—' }}</dd>
                <dt class="col-md-3 text-muted">Nội dung công việc</dt>
                <dd class="col-md-9">{!! $reconciliationRow->work_content ? nl2br(e($reconciliationRow->work_content)) : '—' !!}</dd>
                <dt class="col-md-3 text-muted">Giải trình</dt>
                <dd class="col-md-9">{!! $reconciliationRow->explanation ? nl2br(e($reconciliationRow->explanation)) : '—' !!}</dd>
                <dt class="col-md-3 text-muted">Ghi chú</dt>
                <dd class="col-md-9">{!! $reconciliationRow->notes ? nl2br(e($reconciliationRow->notes)) : '—' !!}</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
