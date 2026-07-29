@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Danh sách BCH</h1>
            <a class="btn btn-primary" href="{{ route('command-centers.index') }}">Làm mới</a>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tên BCH</th>
                        <th>Ghi chú</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($commandCenters as $commandCenter)
                        <tr>
                            <td>{{ $commandCenter->name }}</td>
                            <td>{{ $commandCenter->note ?? '-' }}</td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('command-centers.edit', $commandCenter->id) }}">Sửa</a>
                                <form method="POST" action="{{ route('command-centers.delete', $commandCenter->id) }}" class="d-inline" onsubmit="return confirm('Xoá BCH này?')">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Xoá</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted">Chưa có dữ liệu.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
