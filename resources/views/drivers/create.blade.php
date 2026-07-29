@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Thêm tài xế</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('drivers.store') }}" class="card p-3">
            @csrf
            <input type="hidden" name="redirect" value="{{ $redirect }}">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Họ tên</label>
                    <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">SĐT</label>
                    <input type="text" name="phone" class="form-control" value="{{ old('phone') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Số CCCD</label>
                    <input type="text" name="cccd_no" class="form-control" value="{{ old('cccd_no') }}">
                </div>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary" type="submit">Lưu</button>
                <a class="btn btn-outline-secondary" href="{{ $redirect ?: url()->previous() }}">Quay lại</a>
            </div>
        </form>
    </div>
@endsection
