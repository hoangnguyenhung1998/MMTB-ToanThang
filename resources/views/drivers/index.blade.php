@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Danh sách tài xế</h1>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" href="{{ route('drivers.import.form') }}">Import Excel</a>
                <a class="btn btn-outline-success" href="{{ route('drivers.export', request()->query()) }}">Xuất Excel</a>
                <a class="btn btn-primary" href="{{ route('drivers.create') }}">Thêm tài xế</a>
            </div>
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

        <form method="GET" class="row g-2 mb-3">
            <div class="col-auto">
                <input type="text" name="q" class="form-control" placeholder="Tên, mã máy, SĐT hoặc CCCD" value="{{ $search }}">
            </div>
            <div class="col-auto"><select class="form-select" name="zalo" aria-label="Liên kết Zalo">
                <option value="">Tất cả</option><option value="linked" @selected(request('zalo')==='linked')>Đã liên kết Zalo</option><option value="unlinked" @selected(request('zalo')==='unlinked')>Chưa liên kết Zalo</option>
            </select></div>
            <div class="col-auto">
                <button class="btn btn-outline-primary" type="submit">Tìm kiếm</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tài xế</th><th>Tên / ID Zalo</th><th>Họ tên theo CCCD</th>
                        <th>SĐT</th>
                        <th>CCCD</th><th>Ngày sinh</th><th>Máy đang gắn</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($drivers as $driver)
                        <tr>
                            <td>{{ $driver->name }}</td>
                            <td>@forelse($senders->get($driver->id, collect()) as $sender)<div>{{ $senderNames[$sender] ?? '—' }} · {{ $sender }}</div>@empty Chưa liên kết Zalo @endforelse</td>
                            <td>{{ $legal[$driver->id]['person_name'] ?? '—' }}</td>
                            <td><form method="post" action="{{ route('drivers.phone',$driver) }}" class="d-flex gap-2">@csrf @method('PATCH')
                                <input class="form-control form-control-sm" name="phone" type="tel" maxlength="255" aria-label="Số điện thoại của {{ $driver->name }}" value="{{ $driver->phone }}"><button class="btn btn-sm btn-outline-primary">Lưu</button>
                            </form></td>
                            <td>{{ $legal[$driver->id]['identity'] ?? $driver->cccd_no ?? '—' }}</td>
                            <td>{{ $legal[$driver->id]['birth_date'] ?? '—' }}</td>
                            <td>{{ $codes[$machines[$driver->id] ?? 0] ?? '—' }}</td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('drivers.show', $driver) }}">Xem</a>
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('drivers.edit', $driver) }}">Sửa</a>
                                <form method="POST" action="{{ route('drivers.delete', $driver) }}" class="d-inline" onsubmit="return confirm('Xoá tài xế này?')">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Xoá</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted">Chưa có tài xế.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>
            {{ $drivers->links() }}
        </div>
    </div>
@endsection
