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
                <input type="text" name="q" class="form-control" placeholder="Tìm theo tên/SĐT/CCCD" value="{{ $search }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary" type="submit">Tìm kiếm</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Họ tên</th>
                        <th>SĐT</th>
                        <th>CCCD</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($drivers as $driver)
                        <tr>
                            <td>{{ $driver->name }}</td>
                            <td>{{ $driver->phone ?? '-' }}</td>
                            <td>{{ $driver->cccd_no ?? '-' }}</td>
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
                            <td colspan="4" class="text-center text-muted">Chưa có tài xế.</td>
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
