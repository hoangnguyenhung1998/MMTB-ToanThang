@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;
        use Illuminate\Support\Str;
    @endphp

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Chi tiết máy: {{ $machine->asset_code }}</h1>
            <a class="btn btn-outline-secondary" href="{{ route('machines.index') }}">Quay lại</a>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
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
            <div class="card-header">Thông tin hiện tại</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Mã máy:</strong> {{ $currentInfo['asset_code'] }}</div>
                    <div class="col-md-4"><strong>Trạng thái:</strong> {{ $currentInfo['status'] }}</div>
                    <div class="col-md-4"><strong>App trả:</strong>
                        @if ($machine->status === 'RETURNED')
                            {{ $machine->returned_to_app ? 'Đã đẩy app trả' : 'Chưa đẩy app trả' }}
                        @else
                            -
                        @endif
                    </div>
                    <div class="col-md-4"><strong>GPS đã thêm file:</strong> {{ $currentInfo['gps_file_added'] ? 'Có' : 'Chưa' }}</div>
                    <div class="col-md-4"><strong>Dự án hiện tại:</strong> {{ $currentInfo['current_project']['name'] ?? '-' }}</div>
                    <div class="col-md-4"><strong>BCH hiện tại:</strong> {{ $currentInfo['current_command_center']['name'] ?? '-' }}</div>
                    <div class="col-md-4"><strong>Loại máy:</strong> {{ $machine->machine_type ?? '-' }}</div>
                    <div class="col-md-4"><strong>Thời gian vào gần nhất:</strong> {{ $currentInfo['last_time_in'] ?? '-' }}</div>
                    <div class="col-md-4"><strong>Thời gian ra gần nhất:</strong> {{ $currentInfo['last_time_out'] ?? '-' }}</div>
                    <div class="col-md-4"><strong>Tài xế hiện tại:</strong> {{ $currentInfo['current_driver']['name'] ?? '-' }}</div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Thao tác nhanh</div>
            <div class="card-body d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="{{ route('ops.handover.form', $machine) }}">Bàn giao</a>
                <form method="POST" action="{{ route('ops.activate.submit', $machine) }}" class="d-inline-flex">
                    @csrf
                    <input type="datetime-local" name="time" class="form-control form-control-sm me-2" required>
                    <button class="btn btn-outline-success" type="submit">Kích hoạt</button>
                </form>
                <a class="btn btn-outline-warning" href="{{ route('ops.transfer.form', $machine) }}">Điều chuyển</a>
                <a class="btn btn-outline-danger" href="{{ route('ops.return.form', $machine) }}">Trả</a>
                <a class="btn btn-outline-secondary" href="{{ route('ops.assign-driver.form', $machine) }}">Gán tài xế</a>
                <a class="btn btn-outline-dark" href="{{ route('machine-documents.index', $machine) }}">Giấy tờ xe</a>
                @if ($machine->status === 'RETURNED' && !$machine->returned_to_app)
                    <form method="POST" action="{{ route('ops.return-app.mark', $machine) }}" class="d-inline" onsubmit="return confirm('Xác nhận máy này đã được trả trên app?')">
                        @csrf
                        <button class="btn btn-danger" type="submit">Đã đẩy app</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">Lịch sử dự án</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Dự án</th>
                                    <th>BCH</th>
                                    <th>Vào</th>
                                    <th>Ra</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($assignments as $assignment)
                                    <tr>
                                        <td>{{ $assignment['project']['name'] ?? '-' }}</td>
                                        <td>{{ $assignment['command_center']['name'] ?? '-' }}</td>
                                        <td>{{ $assignment['time_in'] }}</td>
                                        <td>{{ $assignment['time_out'] ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">Chưa có dữ liệu.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">Lịch sử tài xế</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tài xế</th>
                                    <th>Bắt đầu</th>
                                    <th>Kết thúc</th>
                                    <th class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($driverHistory as $history)
                                    <tr>
                                        <td>{{ $history['driver']['name'] ?? '-' }}</td>
                                        <td>{{ $history['started_at'] }}</td>
                                        <td>{{ $history['ended_at'] ?? '-' }}</td>
                                        <td class="text-end">
                                            @if ($history['driver'])
                                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('drivers.show', $history['driver']['id']) }}">Xem tài xế</a>
                                            @endif
                                            <a class="btn btn-sm btn-outline-primary" href="{{ route('machines.change-driver.form', $machine) }}">Đổi tài xế</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">Chưa có dữ liệu.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">Lịch sử sự kiện</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Loại</th>
                            <th>Thời gian</th>
                            <th>Từ dự án</th>
                            <th>Từ BCH</th>
                            <th>Đến dự án</th>
                            <th>Đến BCH</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr>
                                <td>{{ $event['type'] }}</td>
                                <td>{{ $event['occurred_at'] }}</td>
                                <td>{{ $event['from_project']['name'] ?? '-' }}</td>
                                <td>{{ $event['from_command_center']['name'] ?? '-' }}</td>
                                <td>{{ $event['to_project']['name'] ?? '-' }}</td>
                                <td>{{ $event['to_command_center']['name'] ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted">Chưa có dữ liệu.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">Biên bản</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Loại biên bản</th>
                            <th>Thời gian</th>
                            <th>Dự án/BCH liên quan</th>
                            <th>Preview</th>
                            <th>Tệp</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($proofEvents as $event)
                            @php
                                $proofPath = $event->proof_file_path;
                                $proofUrl = $proofPath ? Storage::disk('public')->url($proofPath) : null;
                                $isImage = $proofPath ? Str::of($proofPath)->lower()->endsWith(['.jpg', '.jpeg', '.png', '.webp', '.gif']) : false;
                                $typeLabel = match ($event->type) {
                                    'HANDOVER' => 'Biên bản bàn giao',
                                    'TRANSFER' => 'Biên bản điều chuyển',
                                    'RETURN' => 'Biên bản trả',
                                    default => $event->type,
                                };
                            @endphp
                            <tr>
                                <td>{{ $typeLabel }}</td>
                                <td>{{ $event->occurred_at }}</td>
                                <td>
                                    Từ: {{ $event->fromProject?->name ?? '-' }} / {{ $event->fromCommandCenter?->name ?? '-' }}<br>
                                    Đến: {{ $event->toProject?->name ?? '-' }} / {{ $event->toCommandCenter?->name ?? '-' }}
                                </td>
                                <td>
                                    @if ($proofUrl && $isImage)
                                        <img src="{{ $proofUrl }}" alt="proof" style="max-height: 80px;">
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-2 flex-wrap">
                                        @if (!$proofUrl)
                                            <span class="text-muted">Không có file</span>
                                        @elseif ($isImage)
                                            <a class="btn btn-sm btn-outline-primary" href="{{ $proofUrl }}" target="_blank">Xem ảnh</a>
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ $proofUrl }}" download>Lưu ảnh</a>
                                        @else
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ $proofUrl }}" download>Tải file</a>
                                        @endif

                                        @if (!$proofUrl && $event->type === 'HANDOVER')
                                            <a class="btn btn-sm btn-outline-danger" href="{{ route('machine-events.edit-proof', [$machine, $event]) }}">Bổ sung biên bản</a>
                                        @endif

                                        <a class="btn btn-sm btn-outline-warning" href="{{ route('machine-events.edit-proof', [$machine, $event]) }}">Sửa biên bản</a>
                                        <form method="POST" action="{{ route('machine-events.destroy-proof', [$machine, $event]) }}" onsubmit="return confirm('Xóa file biên bản này?')" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Xóa biên bản</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted">Chưa có biên bản.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
@endsection
