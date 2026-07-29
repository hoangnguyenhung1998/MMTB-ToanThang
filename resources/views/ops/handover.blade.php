@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Bàn giao máy: {{ $machine->asset_code }}</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('ops.handover.submit', $machine) }}" enctype="multipart/form-data" class="card p-3">
            @csrf
            <div class="mb-3">
                <label class="form-label">Dự án</label>
                <select name="project_id" class="form-select" required>
                    <option value="">-- Chọn dự án --</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected(old('project_id') == $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Ban chỉ huy</label>
                <select name="command_center_id" class="form-select" required>
                    <option value="">-- Chọn BCH --</option>
                    @foreach ($commandCenters as $commandCenter)
                        <option value="{{ $commandCenter->id }}" @selected(old('command_center_id') == $commandCenter->id)>
                            {{ $commandCenter->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Thời gian vào</label>
                <input type="datetime-local" name="time_in" class="form-control" value="{{ old('time_in') }}" required>
            </div>
            <div class="mb-3">
                <label class="form-label">File chứng từ</label>
                <input type="file" name="proof_file" class="form-control" required>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Xác nhận</button>
                <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Hủy</a>
            </div>
        </form>
    </div>
@endsection
