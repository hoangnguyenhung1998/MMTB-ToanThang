@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Cập nhật BCH</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('command-centers.update', $commandCenter->id) }}" class="card p-3">
            @csrf
            @method('PUT')
            <div class="mb-3">
                <label class="form-label">Tên BCH</label>
                <input type="text" name="name" class="form-control" value="{{ old('name', $commandCenter->name) }}" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Ghi chú</label>
                <textarea name="note" class="form-control" rows="3">{{ old('note', $commandCenter->note) }}</textarea>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Cập nhật</button>
                <a class="btn btn-outline-secondary" href="{{ route('command-centers.index') }}">Quay lại</a>
            </div>
        </form>
    </div>
@endsection
