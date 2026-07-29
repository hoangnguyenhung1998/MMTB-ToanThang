@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Điều chuyển máy: {{ $machine->asset_code }}</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (!$currentAssignment)
            <div class="alert alert-warning">
                Máy chưa có assignment đang mở. Vui lòng kiểm tra lại trước khi điều chuyển.
            </div>
        @endif

        <form method="POST" action="{{ route('ops.transfer.submit', $machine) }}" enctype="multipart/form-data" class="card p-3">
            @csrf
            <div class="mb-3">
                <label class="form-label">Dự án hiện tại</label>
                <input type="text" class="form-control" value="{{ $currentAssignment?->project?->name ?? 'Chưa có' }}" readonly>
                <input type="hidden" name="from_project_id" value="{{ old('from_project_id', $currentAssignment?->project_id) }}">
            </div>
            <div class="mb-3">
                <label class="form-label">BCH hiện tại</label>
                <input type="text" class="form-control" value="{{ $currentAssignment?->commandCenter?->name ?? 'Chưa có' }}" readonly>
                <input type="hidden" name="from_command_center_id" value="{{ old('from_command_center_id', $currentAssignment?->command_center_id) }}">
            </div>
            <div class="mb-3">
                <label class="form-label">Dự án chuyển đến</label>
                <select name="to_project_id" class="form-select" required>
                    <option value="">-- Chọn dự án --</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected(old('to_project_id') == $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">BCH chuyển đến</label>
                <select name="to_command_center_id" class="form-select" required>
                    <option value="">-- Chọn BCH --</option>
                    @foreach ($commandCenters as $commandCenter)
                        <option value="{{ $commandCenter->id }}" @selected(old('to_command_center_id') == $commandCenter->id)>
                            {{ $commandCenter->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Thời gian ra</label>
                    <input type="datetime-local" name="time_out" class="form-control" value="{{ old('time_out') }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Thời gian vào</label>
                    <input type="datetime-local" name="time_in" class="form-control" value="{{ old('time_in') }}" required>
                </div>
            </div>
            <div class="mb-3 mt-3" id="proof-file-wrapper">
                <label class="form-label">File chứng từ</label>
                <input type="file" name="proof_file" class="form-control">
                <div class="form-text">Bắt buộc khi đổi dự án.</div>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Xác nhận</button>
                <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Hủy</a>
            </div>
        </form>
        <script>
            const fromProject = document.querySelector('input[name="from_project_id"]');
            const toProject = document.querySelector('select[name="to_project_id"]');
            const proofWrapper = document.getElementById('proof-file-wrapper');
            const proofInput = document.querySelector('input[name="proof_file"]');

            const toggleProof = () => {
                const sameProject = fromProject.value && toProject.value && fromProject.value === toProject.value;
                proofWrapper.style.display = sameProject ? 'none' : 'block';
                if (sameProject) {
                    proofInput.value = '';
                }
            };

            toProject.addEventListener('change', toggleProof);
            toggleProof();
        </script>
    </div>
@endsection
