@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Trả máy: {{ $machine->asset_code }}</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('ops.return.submit', $machine) }}" enctype="multipart/form-data" class="card p-3">
            @csrf
            <div class="mb-3">
                <label class="form-label">Thời gian ra</label>
                <input type="datetime-local" name="time_out" class="form-control" value="{{ old('time_out') }}" required>
            </div>
            <div class="mb-3">
                <label class="form-label">File chứng từ</label>
                <input type="file" name="proof_file" class="form-control" required>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="app_return_confirmed" value="1" id="app_return_confirmed" @checked(old('app_return_confirmed'))>
                <label class="form-check-label" for="app_return_confirmed">
                    Xác nhận đã trả máy trên app
                    <span class="text-muted">(không bắt buộc, có thể cập nhật sau)</span>
                </label>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Xác nhận</button>
                <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Hủy</a>
            </div>
        </form>
    </div>
@endsection
