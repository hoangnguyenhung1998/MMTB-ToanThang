@extends('layouts.app')

@section('content')
    @php($dashboard = app(\App\Services\Reconciliation\ReconciliationPeriodDashboardService::class)->dashboard($reconciliationPeriod, request()->query()))
    @php($summary = $dashboard['summary'])

    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col">
                <h1 class="h3 mb-0">{{ $reconciliationPeriod->name }}</h1>
                <div class="text-muted">
                    {{ \Carbon\Carbon::parse($reconciliationPeriod->date_from)->format('d/m/Y') }}
                    –
                    {{ \Carbon\Carbon::parse($reconciliationPeriod->date_to)->format('d/m/Y') }}
                    · {{ $reconciliationPeriod->status }}
                </div>
            </div>
            <div class="col-auto d-flex flex-wrap gap-2">
                @can('importOcr', $reconciliationPeriod)
                    <a href="{{ route('reconciliation-periods.ocr-import.create', $reconciliationPeriod) }}" class="btn btn-outline-primary">
                        Nhập dữ liệu OCR
                    </a>
                @endcan

                @can('reviewMonthly', $reconciliationPeriod)
                    <a href="{{ route('reconciliation-periods.machine-review', $reconciliationPeriod) }}" class="btn btn-primary">
                        Mở đối soát
                    </a>
                @endcan

                @can('export', $reconciliationPeriod)
                    @if (($summary->total_rows ?? 0) > 0)
                        <a href="{{ route('reconciliation-periods.export', $reconciliationPeriod) }}" class="btn btn-outline-success">
                            Xuất Excel
                        </a>
                    @endif
                @endcan
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if (session('ocr_import_summary'))
            @php($importSummary = session('ocr_import_summary'))
            <div class="alert alert-success">
                <div class="fw-semibold mb-2">Nhập OCR thành công</div>
                <div class="row g-2">
                    <div class="col-md-3">Tổng dòng nguồn: {{ number_format($importSummary['total_source'] ?? 0, 0, ',', '.') }}</div>
                    <div class="col-md-3">Dòng đủ điều kiện nhập: {{ number_format($importSummary['importable'] ?? 0, 0, ',', '.') }}</div>
                    <div class="col-md-3">Đã nhập: {{ number_format($importSummary['imported'] ?? 0, 0, ',', '.') }}</div>
                    <div class="col-md-3">Đã cập nhật: {{ number_format($importSummary['updated'] ?? 0, 0, ',', '.') }}</div>
                    <div class="col-md-3">Không thay đổi: {{ number_format($importSummary['unchanged'] ?? 0, 0, ',', '.') }}</div>
                    <div class="col-md-3">Cảnh báo đã nhập: {{ number_format($importSummary['warning_imported'] ?? 0, 0, ',', '.') }}</div>
                    <div class="col-md-3">Lỗi đã bỏ qua: {{ number_format($importSummary['invalid_skipped'] ?? 0, 0, ',', '.') }}</div>
                    <div class="col-md-3">Dòng trống đã bỏ qua: {{ number_format($importSummary['empty_skipped'] ?? 0, 0, ',', '.') }}</div>
                    <div class="col-md-3">Dòng văn bản không có giờ đã cập nhật: {{ number_format($importSummary['text_only_updated'] ?? 0, 0, ',', '.') }}</div>
                </div>
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body"><div class="text-muted small">Tổng dòng</div><div class="h4 mb-0">{{ number_format($summary->total_rows ?? 0, 0, ',', '.') }}</div></div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body"><div class="text-muted small">Đã xác nhận</div><div class="h4 mb-0 text-success">{{ number_format($summary->confirmed_rows ?? 0, 0, ',', '.') }}</div></div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body"><div class="text-muted small">Cần kiểm tra</div><div class="h4 mb-0 text-warning">{{ number_format($summary->attention_rows ?? 0, 0, ',', '.') }}</div></div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body"><div class="text-muted small">Chưa xác nhận</div><div class="h4 mb-0 text-danger">{{ number_format($summary->unconfirmed_rows ?? 0, 0, ',', '.') }}</div></div></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Bộ lọc máy</div>
            <div class="card-body">
                <form method="GET" action="{{ route('reconciliation-periods.show', $reconciliationPeriod) }}" class="row g-2">
                    <div class="col-md-4">
                        <input type="search" name="asset_code" value="{{ request('asset_code') }}" class="form-control" placeholder="Mã máy">
                    </div>
                    <div class="col-md-3">
                        <label class="form-check mt-2">
                            <input type="checkbox" name="needs_attention" value="1" class="form-check-input" @checked(request('needs_attention') === '1')>
                            <span class="form-check-label">Chỉ máy cần xử lý</span>
                        </label>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary w-100">Lọc</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Tổng quan theo máy</div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Mã máy</th>
                            <th>Tên máy</th>
                            <th>Tổng ngày</th>
                            <th>Có dữ liệu</th>
                            <th>Cảnh báo</th>
                            <th>Lỗi</th>
                            <th>Chưa duyệt</th>
                            <th>Chưa xác nhận</th>
                            <th>Tiến độ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dashboard['machines'] as $machine)
                            @php($progress = $machine->total_days ? round(($machine->total_days - $machine->unconfirmed_days) * 100 / $machine->total_days) : 0)
                            <tr>
                                <td class="fw-semibold">{{ $machine->asset_code }}</td>
                                <td>{{ $machine->machine_name }}</td>
                                <td>{{ $machine->total_days }}</td>
                                <td>{{ $machine->days_with_data }}</td>
                                <td>{{ $machine->warning_days }}</td>
                                <td>{{ $machine->error_days }}</td>
                                <td>{{ $machine->unreviewed_days }}</td>
                                <td>{{ $machine->unconfirmed_days }}</td>
                                <td style="min-width: 150px">
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" style="width: {{ $progress }}%">{{ $progress }}%</div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('reconciliation-periods.machine-review', ['reconciliationPeriod' => $reconciliationPeriod, 'machine_id' => $machine->machine_id]) }}" class="btn btn-sm btn-primary">
                                        Mở đối soát theo máy
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    Không có máy trong kỳ đối soát.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
