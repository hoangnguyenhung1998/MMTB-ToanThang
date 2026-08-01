@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col">
                <h1 class="h3 mb-0">Đối soát theo máy</h1>
                <div class="text-muted">
                    {{ $reconciliationPeriod->name }}
                    ·
                    {{ \Carbon\Carbon::parse($reconciliationPeriod->date_from)->format('d/m/Y') }}
                    –
                    {{ \Carbon\Carbon::parse($reconciliationPeriod->date_to)->format('d/m/Y') }}
                </div>
            </div>
            <div class="col-auto">
                <a href="{{ route('reconciliation-periods.show', $reconciliationPeriod) }}" class="btn btn-outline-secondary">
                    Quay lại tổng quan kỳ
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <div class="row g-3">
            <div class="col-lg-3">
                <div class="card mb-3">
                    <div class="card-header">Máy</div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('reconciliation-periods.machine-review', $reconciliationPeriod) }}">
                            <input type="search" name="machine_search" value="{{ request('machine_search') }}" class="form-control form-control-sm mb-2" placeholder="Tìm mã tài sản">
                            <button type="submit" class="btn btn-sm btn-outline-primary w-100">Tìm</button>
                        </form>
                    </div>
                    <div class="list-group list-group-flush">
                        @forelse ($machines as $machine)
                            <a href="{{ route('reconciliation-periods.machine-review', ['reconciliationPeriod' => $reconciliationPeriod, 'machine_id' => $machine->id]) }}"
                               class="list-group-item list-group-item-action {{ optional($selectedMachine)->id === $machine->id ? 'active' : '' }}">
                                <div class="fw-semibold">{{ $machine->asset_code }}</div>
                                <div class="small">{{ $machine->name }}</div>
                                <div class="small">
                                    {{ $machine->confirmed_rows }}/{{ $machine->total_rows }} xác nhận
                                </div>
                            </a>
                        @empty
                            <div class="list-group-item text-muted">Không có máy trong kỳ.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-lg-9">
                <div class="card mb-3">
                    <div class="card-header d-flex flex-wrap justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">{{ $selectedMachine->asset_code ?? 'Chưa chọn máy' }}</div>
                            <div class="small text-muted">{{ $selectedMachine->name ?? '' }}</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            @if ($previousMachine)
                                <a href="{{ route('reconciliation-periods.machine-review', ['reconciliationPeriod' => $reconciliationPeriod, 'machine_id' => $previousMachine->id]) }}" class="btn btn-sm btn-outline-secondary">
                                    Máy trước
                                </a>
                            @endif
                            @if ($nextMachine)
                                <a href="{{ route('reconciliation-periods.machine-review', ['reconciliationPeriod' => $reconciliationPeriod, 'machine_id' => $nextMachine->id]) }}" class="btn btn-sm btn-outline-secondary">
                                    Máy tiếp theo
                                </a>
                            @endif
                        </div>
                        <form method="GET" action="{{ route('reconciliation-periods.machine-review', $reconciliationPeriod) }}" class="d-flex flex-wrap gap-2">
                            <input type="hidden" name="machine_id" value="{{ optional($selectedMachine)->id }}">
                            <select name="status" class="form-select form-select-sm" style="width: 150px">
                                <option value="">Mọi trạng thái</option>
                                @foreach (['DRAFT', 'REVIEWED', 'CONFIRMED', 'REJECTED'] as $status)
                                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                            <label class="form-check form-check-inline align-self-center mb-0">
                                <input type="checkbox" name="only_unconfirmed" value="1" class="form-check-input" @checked(request('only_unconfirmed') === '1')>
                                <span class="form-check-label small">Chưa xác nhận</span>
                            </label>
                            <button type="submit" class="btn btn-sm btn-outline-primary">Lọc</button>
                        </form>
                    </div>

                    @if ($selectedMachine)
                        <form method="POST" action="{{ route('reconciliation-periods.machine-review.bulk-update', $reconciliationPeriod) }}">
                            @csrf
                            <input type="hidden" name="machine_id" value="{{ $selectedMachine->id }}">

                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-hover mb-0 align-middle">
                                    <thead>
                                        <tr>
                                            <th>Lưu</th>
                                            <th>XN</th>
                                            <th>Ngày</th>
                                            <th>BCH</th>
                                            <th>PM BĐ</th>
                                            <th>PM KT</th>
                                            <th>NT sáng</th>
                                            <th>NT chiều</th>
                                            <th>TC trưa</th>
                                            <th>TC chiều</th>
                                            <th>TC tối</th>
                                            <th>Vị trí</th>
                                            <th>Lỗi/Giải trình</th>
                                            <th>Nội dung CV</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($rows as $row)
                                            <tr>
                                                <td>
                                                    <input type="checkbox" name="rows[{{ $row->id }}][selected]" value="1" data-row-checkbox>
                                                </td>
                                                <td>
                                                    @if ($row->status === 'REVIEWED')
                                                        <input type="checkbox" name="row_ids[]" value="{{ $row->id }}" form="bulk-confirm-form">
                                                    @endif
                                                </td>
                                                <td>{{ \Carbon\Carbon::parse($row->work_date)->format('d/m/Y') }}</td>
                                                <td>{{ $row->source_bch ?? $row->command_center_name ?? '-' }}</td>
                                                <td>{{ $row->pm_start_time ?? $row->ocr_pm_start_time ?? $row->ocr_start_time ?? '-' }}</td>
                                                <td>{{ $row->pm_end_time ?? $row->ocr_pm_end_time ?? $row->ocr_end_time ?? '-' }}</td>
                                                <td>{{ $row->nt_morning_start_time ?? $row->ocr_nt_morning_start_time ?? '-' }} - {{ $row->nt_morning_end_time ?? $row->ocr_nt_morning_end_time ?? '-' }}</td>
                                                <td>{{ $row->nt_afternoon_start_time ?? $row->ocr_nt_afternoon_start_time ?? '-' }} - {{ $row->nt_afternoon_end_time ?? $row->ocr_nt_afternoon_end_time ?? '-' }}</td>
                                                <td>{{ $row->tc_noon_start_time ?? $row->ocr_tc_noon_start_time ?? '-' }} - {{ $row->tc_noon_end_time ?? $row->ocr_tc_noon_end_time ?? '-' }}</td>
                                                <td>{{ $row->tc_afternoon_start_time ?? $row->ocr_tc_afternoon_start_time ?? '-' }} - {{ $row->tc_afternoon_end_time ?? $row->ocr_tc_afternoon_end_time ?? '-' }}</td>
                                                <td>{{ $row->tc_night_start_time ?? $row->ocr_tc_night_start_time ?? '-' }} - {{ $row->tc_night_end_time ?? $row->ocr_tc_night_end_time ?? '-' }}</td>
                                                <td>
                                                    @if (in_array('location', $fields, true))
                                                        <textarea name="rows[{{ $row->id }}][location]" class="form-control form-control-sm" rows="1">{{ $row->location }}</textarea>
                                                    @else
                                                        {{ $row->location ?? '-' }}
                                                    @endif
                                                </td>
                                                <td>
                                                    @if (in_array('explanation', $fields, true))
                                                        <textarea name="rows[{{ $row->id }}][explanation]" class="form-control form-control-sm" rows="1">{{ $row->explanation }}</textarea>
                                                    @elseif (in_array('review_note', $fields, true))
                                                        <textarea name="rows[{{ $row->id }}][review_note]" class="form-control form-control-sm" rows="1">{{ $row->review_note }}</textarea>
                                                    @else
                                                        {{ $row->explanation ?? $row->review_note ?? '-' }}
                                                    @endif
                                                </td>
                                                <td>
                                                    @if (in_array('work_content', $fields, true))
                                                        <textarea name="rows[{{ $row->id }}][work_content]" class="form-control form-control-sm" rows="1">{{ $row->work_content }}</textarea>
                                                    @else
                                                        {{ $row->work_content ?? '-' }}
                                                    @endif
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">{{ $row->status }}</span>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="15" class="text-center text-muted py-4">
                                                    Không có dòng đối soát cho máy này.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            <div class="card-footer d-flex flex-wrap justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary">
                                    Lưu các dòng đã chọn
                                </button>
                            </div>
                        </form>

                        <form id="bulk-confirm-form" method="POST" action="{{ route('reconciliation-periods.machine-review.bulk-confirm', $reconciliationPeriod) }}" class="card-footer border-top-0 d-flex flex-wrap justify-content-end gap-2">
                            @csrf
                            <input type="hidden" name="machine_id" value="{{ $selectedMachine->id }}">
                            <button type="submit" class="btn btn-success">
                                Xác nhận các dòng đã chọn
                            </button>
                        </form>

                        <form method="POST" action="{{ route('reconciliation-periods.machine-review.bulk-confirm', $reconciliationPeriod) }}" class="card-footer border-top-0 d-flex flex-wrap justify-content-end gap-2">
                            @csrf
                            <input type="hidden" name="machine_id" value="{{ $selectedMachine->id }}">
                            @foreach ($rows as $row)
                                @if ($row->status === 'REVIEWED')
                                    <input type="hidden" name="row_ids[]" value="{{ $row->id }}">
                                @endif
                            @endforeach
                            <button type="submit" class="btn btn-outline-success">
                                Xác nhận các dòng đã duyệt của máy
                            </button>
                        </form>
                    @else
                        <div class="card-body text-muted">
                            Chọn một máy để bắt đầu đối soát theo tháng.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelector('[data-select-all-rows]')?.addEventListener('change', (event) => {
            document.querySelectorAll('[data-row-checkbox]').forEach((checkbox) => {
                checkbox.checked = event.target.checked;
            });
        });
    </script>
@endsection
