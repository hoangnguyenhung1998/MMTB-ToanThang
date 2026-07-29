@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Cập nhật máy</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('machines.update', $machine) }}" class="card p-3">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Mã máy</label>
                    <input type="text" name="asset_code" class="form-control" value="{{ old('asset_code', $machine->asset_code) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Công ty</label>
                    <select name="company" class="form-select" required>
                        <option value="VINCONS" @selected(old('company', $machine->company) === 'VINCONS')>VINCONS</option>
                        <option value="VINALPHA" @selected(old('company', $machine->company) === 'VINALPHA')>VINALPHA</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Số khung</label>
                    <input type="text" name="chassis_no" class="form-control" value="{{ old('chassis_no', $machine->chassis_no) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Số máy</label>
                    <input type="text" name="engine_no" class="form-control" value="{{ old('engine_no', $machine->engine_no) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Biển số</label>
                    <input type="text" name="plate_no" class="form-control" value="{{ old('plate_no', $machine->plate_no) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Loại máy</label>
                    <input type="text" name="machine_type" class="form-control" value="{{ old('machine_type', $machine->machine_type) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Năm sản xuất</label>
                    <input type="number" name="manufacture_year" class="form-control" value="{{ old('manufacture_year', $machine->manufacture_year) }}" min="1900" max="{{ now()->year + 1 }}" placeholder="Ví dụ: 2021">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Trạng thái</label>
                    <select name="status" class="form-select" required>
                        @foreach (['WAIT_HANDOVER' => 'WAIT_HANDOVER', 'HANDED_OVER' => 'HANDED_OVER', 'ACTIVE' => 'ACTIVE', 'RETURNED' => 'RETURNED'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('status', $machine->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-primary" type="submit">Cập nhật</button>
                <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Quay lại</a>
            </div>
        </form>
    </div>
@endsection
