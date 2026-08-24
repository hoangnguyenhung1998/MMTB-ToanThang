@extends('layouts.app')

@section('content')
@php
    $outcomeLabels = [
        'MATCHED' => 'Đã khớp', 'WARNING' => 'Cảnh báo', 'EXCEPTION' => 'Ngoại lệ',
        'UNRESOLVED' => 'Chưa kết luận',
    ];
    $statusLabels = [
        'PENDING' => 'Chờ xử lý', 'PROCESSING' => 'Đang xử lý', 'RETRY' => 'Chờ thử lại',
        'WAITING_EVIDENCE' => 'Chờ bằng chứng', 'COMPLETED' => 'Hoàn thành', 'FAILED' => 'Thất bại',
    ];
@endphp

<div class="ai-page">
    <header class="ai-heading">
        <div>
            <h1>Đối soát AI</h1>
            <p>Rule Engine và OpenClaw · hiển thị cả trường hợp đã khớp và ngoại lệ.</p>
        </div>
        <span>{{ number_format($summary['total']) }} job</span>
    </header>

    <section class="ai-stats">
        @foreach ([
            ['total', 'Tổng job'], ['matched', 'Đã khớp'], ['warning', 'Cảnh báo'],
            ['exception', 'Ngoại lệ'], ['waiting_evidence', 'Chờ bằng chứng'], ['failed', 'Lỗi xử lý'],
        ] as [$key, $label])
            <div class="ai-stat ai-stat-{{ $key }}"><span>{{ $label }}</span><strong>{{ number_format($summary[$key]) }}</strong></div>
        @endforeach
    </section>

    <form class="app-card ai-filter" method="GET" action="{{ route('ai-reconciliation.index') }}">
        <select name="machine_id">
            <option value="">Tất cả mã máy</option>
            @foreach ($machines as $machine)
                <option value="{{ $machine->id }}" @selected((string) ($filters['machine_id'] ?? '') === (string) $machine->id)>{{ $machine->asset_code }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" title="Từ ngày">
        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" title="Đến ngày">
        <select name="status">
            <option value="">Tất cả trạng thái job</option>
            @foreach ($statusLabels as $status => $label)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="outcome">
            <option value="">Tất cả kết quả</option>
            @foreach ($outcomeLabels as $outcome => $label)
                <option value="{{ $outcome }}" @selected(($filters['outcome'] ?? '') === $outcome)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="btn btn-primary" type="submit">Áp dụng</button>
        <a class="btn btn-outline-secondary" href="{{ route('ai-reconciliation.index') }}">Xóa lọc</a>
    </form>

    <section class="app-card table-card">
        <div class="table-scroll">
            <table class="table table-modern ai-table">
                <thead><tr><th>Job</th><th>Mã máy</th><th>Ngày</th><th>Trạng thái</th><th>Kết quả</th><th>Độ tin cậy</th><th>Xử lý bởi</th><th>Lệnh</th><th></th></tr></thead>
                <tbody>
                @forelse ($jobs as $job)
                    @php($submission = $job->latestSubmission)
                    <tr>
                        <td><strong>#{{ $job->id }}</strong></td>
                        <td class="machine-code">{{ $job->machine?->asset_code ?? '—' }}</td>
                        <td>{{ $job->work_date?->format('d/m/Y') ?? '—' }}</td>
                        <td><span class="ai-badge status-{{ strtolower($job->status) }}">{{ $statusLabels[$job->status] ?? $job->status }}</span></td>
                        <td><span class="ai-badge outcome-{{ strtolower($submission?->outcome ?? 'none') }}">{{ $outcomeLabels[$submission?->outcome] ?? ($submission?->outcome ?? 'Chưa có') }}</span></td>
                        <td>{{ $submission?->confidence !== null ? number_format((float) $submission->confidence * 100, 0).'%' : '—' }}</td>
                        <td>{{ $submission?->agent_name ?? '—' }}</td>
                        <td>{{ $job->commands_count }}</td>
                        <td><a class="btn btn-sm btn-outline-primary" href="{{ route('ai-reconciliation.show', $job) }}">Xem</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="ai-empty">Chưa có job đối soát phù hợp.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="ai-pagination">{{ $jobs->links() }}</div>
</div>

<style>
.ai-page{max-width:1500px;margin:0 auto}.ai-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}.ai-heading h1{margin:0;font-size:24px}.ai-heading p{margin:4px 0 0;color:#64748b}.ai-heading>span{padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font-weight:800}.ai-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:16px}.ai-stat{padding:14px;border:1px solid var(--border);border-radius:14px;background:#fff}.ai-stat span{display:block;color:#64748b;font-size:11px;font-weight:800}.ai-stat strong{display:block;margin-top:5px;font-size:22px}.ai-stat-matched{border-color:#8bd7b4;background:#f1fbf6}.ai-stat-warning,.ai-stat-waiting_evidence{border-color:#f0cf82;background:#fffaf0}.ai-stat-exception,.ai-stat-failed{border-color:#efacb3;background:#fff5f6}.ai-filter{display:grid;grid-template-columns:1.2fr repeat(4,1fr) auto auto;gap:9px;padding:14px;margin-bottom:16px}.ai-table{min-width:1100px}.ai-badge{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:10px;font-weight:800}.outcome-matched,.status-completed{background:#def7ec;color:#087047}.outcome-warning,.outcome-waiting_evidence,.status-pending,.status-retry{background:#fff0d8;color:#9a5700}.outcome-exception,.status-failed{background:#ffe4e8;color:#aa2332}.status-processing{background:#e7efff;color:#2859b8}.outcome-none,.outcome-unresolved{background:#eef2f7;color:#596579}.ai-empty{padding:45px!important;text-align:center;color:#94a3b8!important}.ai-pagination{margin-top:16px}@media(max-width:1100px){.ai-stats{grid-template-columns:repeat(3,1fr)}.ai-filter{grid-template-columns:repeat(3,1fr)}}@media(max-width:650px){.ai-stats,.ai-filter{grid-template-columns:1fr 1fr}}
.status-waiting_evidence{background:#fff0d8;color:#9a5700}
</style>
@endsection
