@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Cập nhật tài xế</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('drivers.update', $driver) }}" class="card p-3">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Họ tên</label>
                    <input type="text" name="name" class="form-control" value="{{ old('name', $driver->name) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">SĐT</label>
                    <input type="text" name="phone" class="form-control" value="{{ old('phone', $driver->phone) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Số CCCD</label>
                    <input type="text" name="cccd_no" class="form-control" value="{{ old('cccd_no', $driver->cccd_no) }}">
                </div>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary" type="submit">Cập nhật</button>
                <a class="btn btn-outline-secondary" href="{{ route('drivers.show', $driver) }}">Quay lại</a>
            </div>
        </form>
    </div>
@endsection
