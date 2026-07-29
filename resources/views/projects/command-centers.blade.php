@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="h4 mb-1">BCH - {{ $project->name }}</h1>
                <p class="text-muted mb-0">BCH dùng chung toàn hệ thống.</p>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('projects.index') }}">Quay lại</a>
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

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">Danh sách BCH</div>
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tên BCH</th>
                                    <th>Ghi chú</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($commandCenters as $commandCenter)
                                    <tr>
                                        <td>{{ $commandCenter->name }}</td>
                                        <td>{{ $commandCenter->note ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="text-center text-muted">Chưa có dữ liệu.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <form method="POST" action="{{ route('project-command-centers.store', $project) }}" class="card p-3">
                    @csrf
                    <h2 class="h6">Thêm BCH mới</h2>
                    <div class="mb-3">
                        <label class="form-label">Tên BCH</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="3">{{ old('note') }}</textarea>
                    </div>
                    <button class="btn btn-primary" type="submit">Thêm</button>
                </form>
            </div>
        </div>
    </div>
@endsection
