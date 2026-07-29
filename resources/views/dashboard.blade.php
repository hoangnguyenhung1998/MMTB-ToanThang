@extends('layouts.app')

@section('content')
    @php
        $badgeMap = [
            'WAIT_HANDOVER' => 'bg-secondary',
            'HANDED_OVER' => 'bg-primary',
            'ACTIVE' => 'bg-success',
            'RETURNED' => 'bg-danger',
        ];
    @endphp

    <div class="container-fluid">
        <h1 class="h4 mb-4">Bảng điều khiển vận hành</h1>

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3 col-xl-2">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted">Tổng số máy</div>
                        <div class="h4 mb-0">{{ $totalMachines }}</div>
                    </div>
                </div>
            </div>
            @foreach ($statuses as $status)
                <div class="col-6 col-md-3 col-xl-2">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="text-muted">{{ $status }}</div>
                            <div class="h4 mb-0">
                                <span class="badge {{ $badgeMap[$status] ?? 'bg-secondary' }}">
                                    {{ $statusCounts[$status] ?? 0 }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="col-6 col-md-3 col-xl-2">
                <div class="card h-100 border-warning">
                    <div class="card-body">
                        <div class="text-muted">Giấy tờ máy sắp/đã hết hạn</div>
                        <div class="h4 mb-0">{{ $machineExpiryCount }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <div class="card h-100 border-warning">
                    <div class="card-body">
                        <div class="text-muted">Giấy tờ tài xế sắp/đã hết hạn</div>
                        <div class="h4 mb-0">{{ $driverExpiryCount }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6">Biểu đồ trạng thái máy</h2>
                        <canvas id="statusChart" height="160"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6">Thống kê theo company</h2>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Company</th>
                                        @foreach ($statuses as $status)
                                            <th>{{ $status }}</th>
                                        @endforeach
                                        <th>Tổng</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($companies as $company)
                                        @php
                                            $total = collect($companyStatusCounts[$company] ?? [])->sum();
                                        @endphp
                                        <tr>
                                            <td>{{ $company }}</td>
                                            @foreach ($statuses as $status)
                                                <td>{{ $companyStatusCounts[$company][$status] ?? 0 }}</td>
                                            @endforeach
                                            <td class="fw-semibold">{{ $total }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6">Số máy theo dự án (đang ở dự án)</h2>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Dự án</th>
                                        <th>Số máy</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($projectCounts as $row)
                                        <tr>
                                            <td>{{ $row->project?->name ?? '-' }}</td>
                                            <td>{{ $row->total }}</td>
                                            <td class="text-end">
                                                @if ($row->project_id)
                                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('machines.index', ['project_id' => $row->project_id]) }}">Xem</a>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">Chưa có dữ liệu.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h2 class="h6 mb-0">Giấy tờ sắp/đã hết hạn (top 10)</h2>
                            <a href="{{ route('expiries.index') }}">Xem tất cả</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Loại</th>
                                        <th>Mã máy / Tài xế</th>
                                        <th>Loại giấy tờ</th>
                                        <th>Ngày hết hạn</th>
                                        <th>Trạng thái</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($expiryItems as $item)
                                        <tr>
                                            <td>{{ $item['type'] === 'machine' ? 'Máy' : 'Tài xế' }}</td>
                                            <td>{{ $item['label'] }}</td>
                                            <td>{{ $item['doc_type'] }}</td>
                                            <td>{{ $item['expiry_date'] }}</td>
                                            <td>
                                                @if ($item['days_diff'] < 0)
                                                    <span class="text-danger">Đã quá hạn {{ abs($item['days_diff']) }} ngày</span>
                                                @else
                                                    <span class="text-warning">Còn {{ $item['days_diff'] }} ngày</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ($item['type'] === 'machine')
                                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('machine-documents.index', $item['machine_id']) }}">Chi tiết</a>
                                                @else
                                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('drivers.show', $item['driver_id']) }}">Chi tiết</a>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">Chưa có giấy tờ sắp hết hạn.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const ctx = document.getElementById('statusChart');
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: @json($statuses),
                datasets: [{
                    label: 'Số lượng',
                    data: @json(collect($statuses)->map(fn($status) => (int) ($statusCounts[$status] ?? 0))),
                    backgroundColor: ['#6c757d', '#0d6efd', '#198754', '#dc3545'],
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                        }
                    }
                }
            }
        });
    </script>
@endsection
