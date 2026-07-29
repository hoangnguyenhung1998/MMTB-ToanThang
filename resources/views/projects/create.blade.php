@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Thêm dự án</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('projects.store') }}" class="card p-3">
            @csrf
            <div class="mb-3">
                <label class="form-label">Tên dự án</label>
                <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Lưu</button>
                <a class="btn btn-outline-secondary" href="{{ route('projects.index') }}">Quay lại</a>
            </div>
        </form>
    </div>
@endsection
