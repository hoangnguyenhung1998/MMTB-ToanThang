@extends('layouts.app')

@section('content')
@php
    $reviewLabels = [
        'PENDING' => 'Cần duyệt',
        'AUTO_APPROVED' => 'Tự duyệt',
        'APPROVED' => 'Đã duyệt',
        'CORRECTED' => 'Đã sửa',
        'REJECTED' => 'Từ chối',
    ];
    $typeLabels = [
        'UNKNOWN' => 'Chưa phân loại',
        'DAILY_TIMEMARK' => 'Ảnh hằng ngày',
        'WEEKLY_JOURNAL' => 'Nhật trình tuần',
    ];
@endphp

<div class="page-shell ocr-review-dashboard">
    <header class="page-header">
        <div>
            <div class="page-eyebrow">PHASE 13.4.2.1</div>
            <h1 class="page-title">Dashboard hậu kiểm OCR</h1>
            <p class="page-subtitle">Ưu tiên ngoại lệ, duyệt theo lô và kiểm tra nhanh theo máy/ngày.</p>
        </div>
        <span class="ocr-total">{{ $jobs->total() }} kết quả theo bộ lọc</span>
    </header>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <section class="ocr-review-stats">
        @foreach ($reviewLabels as $status => $label)
            <a href="{{ route('ocr-reviews.index', array_merge(request()->except('page'), ['review_status' => $status])) }}"
               class="ocr-review-stat status-{{ strtolower($status) }}">
                <span>{{ $label }}</span>
                <strong>{{ $reviewStatusCounts[$status] ?? 0 }}</strong>
            </a>
        @endforeach
    </section>

    <form method="GET" action="{{ route('ocr-reviews.index') }}" class="app-card ocr-filter-card">
        <div class="ocr-filter-grid">
            <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Mã máy, người gửi hoặc mã tin nhắn">

            <select name="review_status">
                <option value="">Tất cả trạng thái hậu kiểm</option>
                @foreach ($reviewLabels as $status => $label)
                    <option value="{{ $status }}" @selected(($filters['review_status'] ?? '') === $status)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="document_type">
                <option value="">Tất cả loại ảnh</option>
                @foreach ($typeLabels as $type => $label)
                    <option value="{{ $type }}" @selected(($filters['document_type'] ?? '') === $type)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="machine_id">
                <option value="">Tất cả thiết bị</option>
                @foreach ($machines as $machine)
                    <option value="{{ $machine->id }}" @selected((string) ($filters['machine_id'] ?? '') === (string) $machine->id)>{{ $machine->asset_code }}</option>
                @endforeach
            </select>

            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" title="Từ ngày gửi">
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" title="Đến ngày gửi">
        </div>
        <div class="ocr-filter-actions">
            <button class="btn btn-primary" type="submit">Áp dụng</button>
            <a class="btn btn-outline-secondary" href="{{ route('ocr-reviews.index') }}">Xóa lọc</a>
        </div>
    </form>

    <section class="app-card ocr-overview-card">
        <div class="ocr-section-head">
            <div>
                <strong>Tổng quan ảnh hằng ngày theo máy</strong>
                <span>Máy có ảnh chờ duyệt được đưa lên trước.</span>
            </div>
            <form method="GET" action="{{ route('ocr-reviews.index') }}">
                @foreach (request()->except(['overview_date', 'page']) as $key => $value)
                    @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <input type="date" name="overview_date" value="{{ $filters['overview_date'] ?? now()->toDateString() }}" onchange="this.form.submit()">
            </form>
        </div>
        <div class="ocr-machine-groups">
            @forelse ($dailyOverview as $group)
                <div class="ocr-machine-group {{ $group['pending'] > 0 ? 'needs-review' : '' }}">
                    <strong>{{ $group['machine'] }}</strong>
                    <span>{{ $group['date'] }} · {{ $group['total'] }} ảnh</span>
                    <small>{{ $group['completed'] }} đã đạt · {{ $group['pending'] }} cần duyệt · {{ $group['exceptions'] }} ngoại lệ</small>
                </div>
            @empty
                <div class="ocr-empty-group">Chưa có ảnh hằng ngày trong ngày đã chọn.</div>
            @endforelse
        </div>
    </section>

    <form method="POST" action="{{ route('ocr-reviews.bulk') }}" id="bulkReviewForm">
        @csrf
        <section class="app-card ocr-bulk-bar">
            <label><input type="checkbox" id="selectAllJobs"> Chọn tất cả trang này</label>
            <span id="selectedCount">0 job được chọn</span>
            <select name="action" required>
                <option value="approve">Duyệt đúng</option>
                <option value="reject">Từ chối</option>
            </select>
            <input name="review_notes" placeholder="Ghi chú chung (không bắt buộc)">
            <button class="btn btn-primary" type="submit">Áp dụng hàng loạt</button>
        </section>

        <section class="app-card table-card">
            <div class="table-scroll">
                <table class="table table-modern ocr-table">
                    <thead>
                    <tr>
                        <th></th>
                        <th>Job</th>
                        <th>Loại ảnh</th>
                        <th>Hậu kiểm</th>
                        <th>OCR</th>
                        <th>Mã máy</th>
                        <th>Ngày / giờ</th>
                        <th>Người gửi</th>
                        <th>Độ tin cậy</th>
                        <th class="sticky-action">Chi tiết</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($jobs as $job)
                        <tr class="{{ $job->review_status === 'PENDING' ? 'row-pending' : '' }}">
                            <td><input class="job-checkbox" type="checkbox" name="job_ids[]" value="{{ $job->id }}"></td>
                            <td><strong>#{{ $job->id }}</strong></td>
                            <td>{{ $typeLabels[$job->document_type] ?? $job->document_type }}</td>
                            <td><span class="review-badge review-{{ strtolower($job->review_status) }}">{{ $reviewLabels[$job->review_status] ?? $job->review_status }}</span></td>
                            <td>{{ $job->status }}</td>
                            <td class="machine-code">{{ $job->asset_code ?: $job->machine?->asset_code ?: '—' }}</td>
                            <td>
                                {{ $job->extracted_date?->format('d/m/Y') ?: $job->attachment?->message?->sent_at?->format('d/m/Y') ?: '—' }}
                                <small>{{ $job->extracted_time ? substr($job->extracted_time, 0, 5) : '' }}</small>
                            </td>
                            <td>{{ $job->attachment?->message?->sender_name ?: '—' }}</td>
                            <td>{{ $job->confidence !== null ? number_format((float) $job->confidence * 100, 0).'%' : '—' }}</td>
                            <td class="sticky-action"><a class="btn btn-sm btn-outline-primary" href="{{ route('ocr-reviews.show', $job) }}">Xem</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="ocr-empty">Không có kết quả OCR phù hợp.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </form>

    <div class="ocr-pagination">{{ $jobs->links() }}</div>
</div>

<style>
.ocr-total{padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#475569;font-size:12px;font-weight:800}
.ocr-review-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:16px}
.ocr-review-stat{display:flex;align-items:center;justify-content:space-between;padding:15px 17px;border:1px solid var(--border);border-radius:15px;background:#fff;color:#475569;text-decoration:none;box-shadow:var(--shadow-sm)}
.ocr-review-stat span{font-size:12px;font-weight:700}.ocr-review-stat strong{font-size:22px;color:#0f172a}
.ocr-review-stat.status-pending{border-color:#f7d58b;background:#fffaf0}
.ocr-filter-card{padding:14px;margin-bottom:16px}.ocr-filter-grid{display:grid;grid-template-columns:1.6fr repeat(3,1fr) repeat(2,.85fr);gap:10px}
.ocr-filter-actions{display:flex;gap:8px;margin-top:10px}.ocr-overview-card{margin-bottom:16px;overflow:hidden}
.ocr-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)}
.ocr-section-head span{display:block;margin-top:3px;color:#64748b;font-size:11px}
.ocr-machine-groups{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:14px}
.ocr-machine-group{padding:12px;border:1px solid #dbe4ef;border-radius:12px;background:#f8fafc}.ocr-machine-group.needs-review{border-color:#f0c36b;background:#fffaf0}
.ocr-machine-group span,.ocr-machine-group small{display:block;margin-top:4px;color:#64748b}.ocr-machine-group small{font-size:10px}
.ocr-empty-group{grid-column:1/-1;padding:20px;color:#94a3b8;text-align:center}
.ocr-bulk-bar{display:grid;grid-template-columns:auto auto 150px minmax(220px,1fr) auto;align-items:center;gap:12px;padding:12px 14px;margin-bottom:10px;background:#eef4ff}
.ocr-bulk-bar label,.ocr-bulk-bar span{font-size:12px;font-weight:700}.ocr-table{min-width:1250px}.ocr-table small{display:block;color:#64748b}
.review-badge{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:10px;font-weight:800}
.review-pending{background:#fff0d8;color:#a05200}.review-auto_approved{background:#e9f8f1;color:#13734d}.review-approved{background:#def7ec;color:#087047}.review-corrected{background:#e8efff;color:#2558c7}.review-rejected{background:#fff0f1;color:#b42332}
.row-pending{background:#fffdf7}.ocr-empty{padding:45px!important;color:#94a3b8!important;text-align:center}.ocr-pagination{margin-top:16px}
@media(max-width:1100px){.ocr-filter-grid{grid-template-columns:repeat(3,1fr)}.ocr-machine-groups{grid-template-columns:repeat(2,1fr)}.ocr-bulk-bar{grid-template-columns:1fr 1fr}}
@media(max-width:700px){.ocr-review-stats,.ocr-filter-grid,.ocr-machine-groups{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.ocr-review-stats,.ocr-filter-grid,.ocr-machine-groups{grid-template-columns:1fr}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('selectAllJobs');
    const checkboxes = [...document.querySelectorAll('.job-checkbox')];
    const counter = document.getElementById('selectedCount');
    const form = document.getElementById('bulkReviewForm');

    const updateCount = () => {
        const count = checkboxes.filter(checkbox => checkbox.checked).length;
        counter.textContent = count + ' job được chọn';
        selectAll.checked = count > 0 && count === checkboxes.length;
        selectAll.indeterminate = count > 0 && count < checkboxes.length;
    };

    selectAll?.addEventListener('change', () => {
        checkboxes.forEach(checkbox => checkbox.checked = selectAll.checked);
        updateCount();
    });
    checkboxes.forEach(checkbox => checkbox.addEventListener('change', updateCount));
    form?.addEventListener('submit', event => {
        if (!checkboxes.some(checkbox => checkbox.checked)) {
            event.preventDefault();
            alert('Anh cần chọn ít nhất một job.');
        }
    });
});
</script>
@endsection
