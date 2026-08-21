@extends('layouts.app')

@section('content')
@php
    $statusLabels = [
        'PENDING' => 'Chờ xử lý', 'PROCESSING' => 'Đang xử lý', 'RETRY' => 'Chờ thử lại',
        'COMPLETED' => 'Hoàn thành', 'EXCEPTION' => 'Cần hậu kiểm', 'FAILED' => 'Thất bại',
    ];
    $typeLabels = [
        'UNKNOWN' => 'Chưa phân loại', 'DAILY_TIMEMARK' => 'Ảnh hằng ngày', 'WEEKLY_JOURNAL' => 'Nhật trình tuần',
    ];
@endphp

<div class="page-shell ocr-page">
    <header class="page-header">
        <div>
            <div class="page-eyebrow">PHASE 13 · OCR</div>
            <h1 class="page-title">Hậu kiểm OCR</h1>
            <p class="page-subtitle">Theo dõi ảnh Zalo, kết quả nhận dạng và các ngoại lệ cần kiểm tra.</p>
        </div>
        <div class="ocr-total">{{ number_format($jobs->total()) }} kết quả</div>
    </header>

    <section class="ocr-stats">
        @foreach (['PENDING', 'COMPLETED', 'EXCEPTION', 'FAILED'] as $status)
            <a href="{{ route('ocr-reviews.index', ['status' => $status]) }}" class="ocr-stat ocr-status-{{ strtolower($status) }}">
                <span>{{ $statusLabels[$status] }}</span>
                <strong>{{ number_format((int) ($statusCounts[$status] ?? 0)) }}</strong>
            </a>
        @endforeach
    </section>

    <form class="app-card filter-card" method="GET" action="{{ route('ocr-reviews.index') }}">
        <div class="filter-heading">Bộ lọc kết quả OCR</div>
        <div class="ocr-filter-grid">
            <input class="form-control" type="search" name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Mã máy, người gửi hoặc mã tin nhắn">
            <select class="form-select" name="status">
                <option value="">Tất cả trạng thái</option>
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select class="form-select" name="document_type">
                <option value="">Tất cả loại ảnh</option>
                @foreach ($typeLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['document_type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select class="form-select" name="machine_id">
                <option value="">Tất cả thiết bị</option>
                @foreach ($machines as $machine)
                    <option value="{{ $machine->id }}" @selected((string) ($filters['machine_id'] ?? '') === (string) $machine->id)>
                        {{ $machine->asset_code }}
                    </option>
                @endforeach
            </select>
            <input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" title="Từ ngày gửi">
            <input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" title="Đến ngày gửi">
        </div>
        <div class="filter-actions">
            <button class="btn btn-primary" type="submit">Áp dụng</button>
            <a class="btn btn-outline-secondary" href="{{ route('ocr-reviews.index') }}">Xóa lọc</a>
        </div>
    </form>

    <section class="app-card table-card">
        <div class="table-scroll">
            <table class="table table-modern ocr-table">
                <thead>
                <tr>
                    <th>Job</th>
                    <th>Loại ảnh</th>
                    <th>Trạng thái</th>
                    <th>Mã máy</th>
                    <th>Ngày / giờ</th>
                    <th>Người gửi</th>
                    <th>Tin nhắn Zalo</th>
                    <th>Độ tin cậy</th>
                    <th class="sticky-action">Chi tiết</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($jobs as $job)
                    <tr>
                        <td><strong>#{{ $job->id }}</strong></td>
                        <td>{{ $typeLabels[$job->document_type] ?? $job->document_type }}</td>
                        <td><span class="ocr-badge ocr-status-{{ strtolower($job->status) }}">{{ $statusLabels[$job->status] ?? $job->status }}</span></td>
                        <td class="machine-code">{{ $job->asset_code ?: $job->machine?->asset_code ?: '—' }}</td>
                        <td>
                            @if ($job->document_type === 'DAILY_TIMEMARK')
                                {{ $job->extracted_date?->format('d/m/Y') ?: '—' }}
                                <small>{{ $job->extracted_time ? substr($job->extracted_time, 0, 5) : '' }}</small>
                            @else
                                {{ $job->attachment?->message?->sent_at?->format('d/m/Y H:i') ?: '—' }}
                            @endif
                        </td>
                        <td>{{ $job->attachment?->message?->sender_name ?: '—' }}</td>
                        <td class="muted-cell">{{ $job->attachment?->message?->message_id ?: '—' }}</td>
                        <td>{{ $job->confidence !== null ? number_format((float) $job->confidence * 100, 0).'%' : '—' }}</td>
                        <td class="sticky-action"><a class="btn btn-sm btn-outline-primary" href="{{ route('ocr-reviews.show', $job) }}">Xem</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="ocr-empty">Không có kết quả OCR phù hợp.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="ocr-pagination">{{ $jobs->links() }}</div>
</div>

<style>
.ocr-total{padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#475569;font-size:12px;font-weight:800}.ocr-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.ocr-stat{display:flex;align-items:center;justify-content:space-between;padding:15px 17px;border:1px solid var(--border);border-radius:15px;background:#fff;color:#475569;box-shadow:var(--shadow-sm)}.ocr-stat span{font-size:12px;font-weight:700}.ocr-stat strong{font-size:22px;color:#0f172a}.ocr-stat.ocr-status-exception{border-color:#f7d58b;background:#fffaf0}.ocr-stat.ocr-status-failed{border-color:#f3c8cd;background:#fff7f8}.ocr-filter-grid{display:grid;grid-template-columns:1.6fr repeat(3,1fr) repeat(2,.85fr);gap:10px}.ocr-table{min-width:1180px}.ocr-table small{display:block;margin-top:2px;color:#64748b}.ocr-badge{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:10px;font-weight:800}.ocr-status-pending,.ocr-status-retry{background:#fff4cc;color:#8a5b00}.ocr-status-processing{background:#e8efff;color:#2558c7}.ocr-status-completed{background:#e9f8f1;color:#13734d}.ocr-status-exception{background:#fff0d8;color:#a05200}.ocr-status-failed{background:#fff0f1;color:#b42332}.ocr-empty{padding:45px!important;color:#94a3b8!important;text-align:center}.ocr-pagination{margin-top:16px}@media(max-width:1100px){.ocr-filter-grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:700px){.ocr-stats,.ocr-filter-grid{grid-template-columns:1fr 1fr}.ocr-stat{padding:12px}.ocr-stat strong{font-size:18px}}@media(max-width:480px){.ocr-stats,.ocr-filter-grid{grid-template-columns:1fr}}
</style>
@endsection
