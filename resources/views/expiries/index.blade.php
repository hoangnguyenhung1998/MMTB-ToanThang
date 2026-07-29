@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Hồ sơ sắp hết hạn</h1>
            <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">Quay lại</a>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link @if ($tab === 'machines') active @endif" href="{{ route('expiries.index', ['tab' => 'machines', 'days' => $days]) }}">
                    Giấy tờ máy
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link @if ($tab === 'drivers') active @endif" href="{{ route('expiries.index', ['tab' => 'drivers', 'days' => $days]) }}">
                    Giấy tờ tài xế
                </a>
            </li>
        </ul>

        @if ($tab === 'drivers')
            <form class="row g-2 mb-3" method="GET">
                <input type="hidden" name="tab" value="drivers">
                <div class="col-12 col-md-2">
                    <select class="form-select" name="days">
                        @foreach ($allowedDays as $option)
                            <option value="{{ $option }}" @selected($days === $option)>{{ $option }} ngày</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <input class="form-control" type="text" name="driver_name" placeholder="Tìm tên tài xế"
                        value="{{ $filters['driver_name'] }}">
                </div>
                <div class="col-12 col-md-3">
                    <select class="form-select" name="driver_doc_type">
                        <option value="">-- Loại giấy tờ --</option>
                        @foreach ($driverDocTypes as $docType)
                            <option value="{{ $docType }}" @selected($filters['driver_doc_type'] === $docType)>{{ $docType }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Lọc</button>
                    <a class="btn btn-outline-secondary" href="{{ route('expiries.index', ['tab' => 'drivers', 'days' => $days]) }}">Xóa lọc</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Tài xế</th>
                            <th>Loại giấy tờ</th>
                            <th>Ngày hết hạn</th>
                            <th>Trạng thái</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($driverDocuments as $document)
                            <tr>
                                <td>{{ $document->driver?->name ?? '-' }}</td>
                                <td>{{ $document->doc_type }}</td>
                                <td>{{ $document->expiry_date }}</td>
                                <td>
                                    @if ($document->days_diff < 0)
                                        <span class="text-danger">Đã quá hạn {{ abs($document->days_diff) }} ngày</span>
                                    @else
                                        <span class="text-warning">Còn {{ $document->days_diff }} ngày</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($document->driver_id)
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('drivers.show', $document->driver_id) }}">Chi tiết</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">Chưa có hồ sơ phù hợp.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>
                {{ $driverDocuments->links() }}
            </div>
        @else
            <form class="row g-2 mb-3" method="GET">
                <input type="hidden" name="tab" value="machines">
                <div class="col-12 col-md-2">
                    <select class="form-select" name="days">
                        @foreach ($allowedDays as $option)
                            <option value="{{ $option }}" @selected($days === $option)>{{ $option }} ngày</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <input class="form-control" type="text" name="machine_code" placeholder="Tìm mã máy"
                        value="{{ $filters['machine_code'] }}">
                </div>
                <div class="col-12 col-md-3">
                    <select class="form-select" name="machine_doc_type">
                        <option value="">-- Loại giấy tờ --</option>
                        @foreach ($machineDocTypes as $docType)
                            <option value="{{ $docType }}" @selected($filters['machine_doc_type'] === $docType)>{{ $docType }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Lọc</button>
                    <a class="btn btn-outline-secondary" href="{{ route('expiries.index', ['tab' => 'machines', 'days' => $days]) }}">Xóa lọc</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Mã máy</th>
                            <th>Loại giấy tờ</th>
                            <th>Ngày hết hạn</th>
                            <th>Trạng thái</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($machineDocuments as $document)
                            <tr>
                                <td>{{ $document->machine?->asset_code ?? '-' }}</td>
                                <td>{{ $document->doc_type }}</td>
                                <td>{{ $document->expiry_date }}</td>
                                <td>
                                    @if ($document->days_diff < 0)
                                        <span class="text-danger">Đã quá hạn {{ abs($document->days_diff) }} ngày</span>
                                    @else
                                        <span class="text-warning">Còn {{ $document->days_diff }} ngày</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($document->machine_id)
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('machine-documents.index', $document->machine_id) }}">Chi tiết</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">Chưa có hồ sơ phù hợp.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>
                {{ $machineDocuments->links() }}
            </div>
        @endif
    </div>
@endsection
