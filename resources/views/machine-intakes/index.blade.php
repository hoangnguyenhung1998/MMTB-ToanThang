@extends('layouts.app')
@section('content')
<div class="page-shell">
    <x-page-header eyebrow="Phase 16.1" title="AI tiếp nhận máy" subtitle="Mỗi hồ sơ chờ cấp mã độc lập; hồ sơ nào có mã trước được bàn giao trước.">
        <x-slot:actions><a class="btn btn-primary" href="{{ route('machine-intakes.create') }}">Tạo hồ sơ tiếp nhận</a></x-slot:actions>
    </x-page-header>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><x-stat-card label="Tổng hồ sơ" :value="$summary['all']" /></div>
        <div class="col-md-3"><x-stat-card label="Chờ cấp mã" :value="$summary['waiting']" /></div>
        <div class="col-md-3"><x-stat-card label="Chờ bàn giao" :value="$summary['handover']" /></div>
        <div class="col-md-3"><x-stat-card label="Đang chuẩn bị" :value="$summary['draft']" /></div>
    </div>
    <div class="app-card p-3 mb-3">
        <form class="row g-2" method="GET">
            <div class="col-md-5"><input class="form-control" name="q" value="{{ $q }}" placeholder="Tìm hồ sơ, số khung, số máy, mã máy"></div>
            <div class="col-md-4"><select class="form-select" name="status"><option value="">Tất cả trạng thái</option>@foreach(\App\Models\MachineIntakeCase::STATUSES as $item)<option value="{{ $item }}" @selected($status === $item)>{{ $item }}</option>@endforeach</select></div>
            <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Lọc</button><a class="btn btn-outline-secondary" href="{{ route('machine-intakes.index') }}">Xóa</a></div>
        </form>
    </div>
    <div class="app-card overflow-hidden">
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Hồ sơ</th><th>Định danh</th><th>Loại máy</th><th>Trạng thái</th><th>Nhận mã</th><th></th></tr></thead><tbody>
        @forelse($cases as $case)<tr>
            <td><strong>{{ $case->reference }}</strong><div class="text-muted small">{{ $case->created_at->format('d/m/Y H:i') }}</div></td>
            <td><div>Khung: <strong>{{ $case->chassis_no ?: 'Chưa xác định' }}</strong></div><div class="small text-muted">Máy: {{ $case->engine_no ?: 'Chưa xác định' }}</div></td>
            <td>{{ $case->machine_type ?: '—' }}<div class="small text-muted">{{ $case->model_name }}</div></td>
            <td><span class="badge {{ $case->status === 'WAIT_ASSET_CODE' ? 'bg-warning text-dark' : ($case->status === 'WAIT_HANDOVER' ? 'bg-success' : 'bg-secondary') }}">{{ $case->status }}</span></td>
            <td>{{ $case->asset_code ?: '—' }}<div class="small text-muted">{{ $case->asset_code_source }}</div></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('machine-intakes.show', $case) }}">Mở hồ sơ</a></td>
        </tr>@empty<tr><td colspan="6" class="text-center text-muted py-5">Chưa có hồ sơ tiếp nhận.</td></tr>@endforelse
        </tbody></table></div>
    </div>
    <div class="mt-3">{{ $cases->links() }}</div>
</div>
@endsection
