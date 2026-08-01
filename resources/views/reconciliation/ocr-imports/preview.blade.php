@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col">
                <h1 class="h3 mb-0">Xem trước dữ liệu OCR</h1>
            </div>
            <div class="col-auto">
                <a href="{{ route('reconciliation-periods.ocr-import.create', $reconciliationPeriod) }}" class="btn btn-outline-secondary">
                    Chọn lại file
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
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
            <div class="card-header">
                Thông tin file
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Tên file</dt>
                    <dd class="col-sm-9">{{ $fileName }}</dd>

                    <dt class="col-sm-3">Worksheet đã đọc</dt>
                    <dd class="col-sm-9">{{ $preview['worksheet'] }}</dd>

                    <dt class="col-sm-3">Kỳ đối soát</dt>
                    <dd class="col-sm-9">{{ $reconciliationPeriod->name }}</dd>

                    <dt class="col-sm-3">Khoảng ngày</dt>
                    <dd class="col-sm-9">
                        {{ \Carbon\Carbon::parse($reconciliationPeriod->date_from)->format('d/m/Y') }}
                        -
                        {{ \Carbon\Carbon::parse($reconciliationPeriod->date_to)->format('d/m/Y') }}
                    </dd>
                </dl>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Tổng dòng</div>
                        <div class="h4 mb-0">{{ $preview['summary']['total'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Có giờ làm</div>
                        <div class="h4 mb-0">{{ $preview['summary']['working_time'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Trống máy/ngày</div>
                        <div class="h4 mb-0">{{ $preview['summary']['blank_machine_day'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Dòng hợp lệ</div>
                        <div class="h4 mb-0 text-success">{{ $preview['summary']['valid'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Dòng cảnh báo</div>
                        <div class="h4 mb-0 text-warning">{{ $preview['summary']['warning'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Dòng lỗi</div>
                        <div class="h4 mb-0 text-danger">{{ $preview['summary']['invalid'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Trùng máy/ngày</div>
                        <div class="h4 mb-0 text-danger">{{ $preview['summary']['duplicate'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Bỏ qua</div>
                        <div class="h4 mb-0 text-secondary">{{ $preview['summary']['skipped'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap gap-2">
                <input type="search" class="form-control form-control-sm" style="max-width: 260px" placeholder="Tìm mã tài sản" data-ocr-search>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-ocr-filter="all">Tất cả</button>
                <button type="button" class="btn btn-outline-success btn-sm" data-ocr-filter="valid">Hợp lệ</button>
                <button type="button" class="btn btn-outline-warning btn-sm" data-ocr-filter="warning">Cảnh báo</button>
                <button type="button" class="btn btn-outline-danger btn-sm" data-ocr-filter="invalid">Lỗi</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-ocr-filter="skipped">Bỏ qua</button>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                Dữ liệu xem trước
                <span class="text-muted small ms-2">
                    Hiển thị tối đa 200 dòng lỗi/cảnh báo và 50 dòng hợp lệ mẫu. Dòng bỏ qua chỉ hiển thị trong thống kê.
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Dòng</th>
                            <th>KEY</th>
                            <th>BCH</th>
                            <th>Ngày</th>
                            <th>Mã tài sản</th>
                            <th>Máy khớp</th>
                            <th>Khoảng giờ</th>
                            <th>Vị trí</th>
                            <th>Lỗi/Giải trình</th>
                            <th>Nội dung CV</th>
                            <th>Trạng thái</th>
                            <th>Thông báo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($preview['display_rows'] as $previewRow)
                            <tr data-ocr-status="{{ $previewRow['status'] }}" data-machine-code="{{ \Illuminate\Support\Str::lower($previewRow['source']['machine_code'] ?? '') }}">
                                <td>{{ $previewRow['source']['source_row'] }}</td>
                                <td>{{ $previewRow['source']['key'] ?? '-' }}</td>
                                <td>{{ $previewRow['source']['source_bch'] ?? '-' }}</td>
                                <td>{{ $previewRow['source']['work_date'] ?? '-' }}</td>
                                <td>{{ $previewRow['source']['machine_code'] ?? '-' }}</td>
                                <td>{{ $previewRow['machine_label'] ?? '-' }}</td>
                                <td>
                                    @forelse ($previewRow['source']['intervals'] as $interval)
                                        <div>{{ $interval['label'] }}: {{ $interval['start'] ?? '-' }} - {{ $interval['end'] ?? '-' }}</div>
                                    @empty
                                        -
                                    @endforelse
                                </td>
                                <td>{{ $previewRow['source']['location'] ?? '-' }}</td>
                                <td>{{ $previewRow['source']['explanation'] ?? '-' }}</td>
                                <td>{{ $previewRow['source']['work_content'] ?? '-' }}</td>
                                <td>
                                    @if ($previewRow['status'] === 'valid')
                                        <span class="badge bg-success">Hợp lệ</span>
                                    @elseif ($previewRow['status'] === 'warning')
                                        <span class="badge bg-warning text-dark">Cảnh báo</span>
                                    @elseif ($previewRow['status'] === 'skipped')
                                        <span class="badge bg-secondary">Bỏ qua</span>
                                    @else
                                        <span class="badge bg-danger">Lỗi</span>
                                    @endif
                                </td>
                                <td>
                                    {{ collect($previewRow['errors'] ?? [])->merge($previewRow['warnings'] ?? [])->implode('; ') ?: '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">
                                    Không có dữ liệu xem trước.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if (! empty($preview['skipped_examples']))
            <div class="card mb-3">
                <div class="card-header">
                    Ví dụ dòng bỏ qua
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        @foreach ($preview['skipped_examples'] as $skippedRow)
                            <li>
                                Dòng {{ $skippedRow['source']['source_row'] }}:
                                {{ $skippedRow['source']['machine_code'] ?? 'Không có mã tài sản' }}
                                -
                                {{ $skippedRow['source']['work_date'] ?? 'Không có ngày' }}
                                -
                                Không phát sinh dữ liệu giờ làm việc
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="d-flex justify-content-end gap-2">
            @php($importableCount = ($preview['summary']['valid'] ?? 0) + ($preview['summary']['warning'] ?? 0))

            <form method="POST" action="{{ route('reconciliation-periods.ocr-import.cancel', $reconciliationPeriod) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" class="btn btn-outline-secondary">
                    Hủy nhập
                </button>
            </form>

            <a href="{{ route('reconciliation-periods.ocr-import.create', $reconciliationPeriod) }}" class="btn btn-outline-primary">
                Chọn lại file
            </a>

            <form method="POST" action="{{ route('reconciliation-periods.ocr-import.confirm', $reconciliationPeriod) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" class="btn btn-primary" @disabled($importableCount === 0)>
                    Xác nhận nhập {{ number_format($importableCount, 0, ',', '.') }} dòng
                </button>
            </form>
        </div>

        @if ($importableCount === 0)
            <div class="alert alert-warning mt-3">
                Không có dòng nào đủ điều kiện nhập
            </div>
        @endif
    </div>

    <script>
        let activeStatus = 'all';
        let machineSearch = '';

        const applyOcrPreviewFilters = () => {
            document.querySelectorAll('[data-ocr-status]').forEach((row) => {
                const matchesStatus = activeStatus === 'all' || row.getAttribute('data-ocr-status') === activeStatus;
                const matchesMachine = !machineSearch || row.getAttribute('data-machine-code').includes(machineSearch);

                row.classList.toggle('d-none', !matchesStatus || !matchesMachine);
            });
        };

        document.querySelectorAll('[data-ocr-filter]').forEach((button) => {
            button.addEventListener('click', () => {
                activeStatus = button.getAttribute('data-ocr-filter');
                applyOcrPreviewFilters();
            });
        });

        document.querySelector('[data-ocr-search]')?.addEventListener('input', (event) => {
            machineSearch = event.target.value.trim().toLowerCase();
            applyOcrPreviewFilters();
        });
    </script>
@endsection
