@extends('layouts.app')
@section('content')
<div class="container-fluid">
    <h1 class="h4">Quản lý công ty</h1>
    <p class="text-muted">Mã công ty được giữ cố định khi đổi tên để bảo toàn liên kết máy và hồ sơ.</p>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('companies.store') }}" class="card card-body mb-3">
        @csrf
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label for="companyCode">Mã công ty</label><input id="companyCode" class="form-control" name="code" value="{{ old('code') }}" maxlength="20" placeholder="SGC" pattern="[A-Z0-9][A-Z0-9_-]{0,19}" required></div>
            <div class="col-md-6"><label for="companyName">Tên hiển thị</label><input id="companyName" class="form-control" name="name" value="{{ old('name') }}" required maxlength="255"></div>
            <div class="col-md-3"><button class="btn btn-primary">Thêm công ty</button></div>
        </div>
    </form>
    @foreach($companies as $company)
        <div class="card card-body mb-2">
            <form method="POST" action="{{ route('companies.update', $company) }}" class="row g-2 align-items-center">
                @csrf @method('PUT')
                <div class="col-md-2"><strong>{{ $company->code }}</strong></div>
                <div class="col-md-5"><input aria-label="Tên {{ $company->code }}" class="form-control" name="name" value="{{ $company->name }}" required maxlength="255"></div>
                <div class="col-md-3"><label><input type="checkbox" name="is_active" value="1" @checked($company->is_active)> Đang sử dụng</label></div>
                <div class="col-md-2"><button class="btn btn-outline-primary">Lưu</button></div>
            </form>
            <form method="POST" action="{{ route('companies.destroy', $company) }}" class="mt-2" onsubmit="return confirm('Xóa công ty chưa được sử dụng này?')">
                @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Xóa</button>
            </form>
        </div>
    @endforeach
</div>
@endsection
