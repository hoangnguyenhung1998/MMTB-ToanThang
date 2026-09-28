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
        'IGNORED_HOUR_METER' => 'Ảnh công-tơ giờ',
        'IGNORED_NON_DAILY_PHOTO' => 'Ảnh không phải Daily',
    ];
    $aiStatusLabels = [
        'PENDING' => 'Đang chờ',
        'RETRY' => 'Đang chờ thử lại',
        'PROCESSING' => 'Đang xử lý',
        'COMPLETED' => 'Đã xử lý',
        'FAILED' => 'Thất bại',
        'SKIPPED' => 'Bỏ qua',
    ];
    $aiResolutionLabels = [
        'RESOLVED' => 'Đã giải quyết',
        'HUMAN_REQUIRED' => 'Cần kiểm tra',
        'NON_DAILY' => 'Không phải Daily',
        'FAILED' => 'Thất bại',
        'SKIPPED_PROTECTED' => 'Bỏ qua · protected',
        'SKIPPED_STALE' => 'Bỏ qua · stale',
    ];
    $workflowLabels = [
        'manual' => 'Manual / cần xử lý',
        'canonical' => 'Đã vào Canonical',
        'protected' => 'Được bảo vệ',
        'reviewed' => 'Đã hậu kiểm',
    ];
    $ocrSourceLabels = [
        'rapidocr' => 'OCR chính / chưa AI',
        'ai_attempted' => 'Đã từng chạy AI',
        'never_ai' => 'Chưa từng chạy AI',
    ];
    $aiFilterLabels = [
        'never' => 'Chưa chạy AI',
        'queued' => 'AI đang chờ',
        'processing' => 'AI đang xử lý',
        'active' => 'AI chờ / đang xử lý',
        'resolved' => 'AI đã giải quyết',
        'human_required' => 'AI cần kiểm tra',
        'non_daily' => 'AI xác định không phải Daily',
        'failed' => 'AI thất bại',
        'skipped' => 'AI bỏ qua / stale',
        'failed_or_skipped' => 'AI thất bại / bỏ qua',
    ];
@endphp

<div class="page-shell ocr-review-dashboard">
    <header class="page-header">
        <div>
            <div class="page-eyebrow">PHASE 13.4.2.1</div>
            <h1 class="page-title">{{ config('daily_photos.enabled') ? 'Ảnh và ngoại lệ OCR' : 'Dashboard hậu kiểm OCR' }}</h1>
            <p class="page-subtitle">Theo dõi ảnh theo máy/ngày và chỉnh sửa khi có ngoại lệ.</p>
        </div>
        <span class="ocr-total">{{ $jobs->total() }} kết quả theo bộ lọc</span>
    </header>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(config('daily_photos.enabled') && $aiRescueDashboard)
    <section class="app-card ai-rescue-dashboard">
        <div class="ocr-section-head">
            <div>
                <strong>AI Rescue cho ảnh Manual</strong>
                <span>Chỉ xử lý ảnh được operator chọn. Preview không tạo job và mỗi ảnh chỉ được xếp hàng một lần.</span>
            </div>
            <span class="ai-manual-total">{{ number_format($aiRescueDashboard['manual_unique_photos']) }} ảnh Manual</span>
        </div>
        <div class="ai-metrics-grid">
            <a href="{{ route('ocr-reviews.index', ['workflow' => 'manual', 'ai_status' => 'never']) }}"><span>Chưa chạy AI</span><strong>{{ number_format($aiRescueDashboard['metrics']['never_attempted']) }}</strong></a>
            <a href="{{ route('ocr-reviews.index', ['ai_status' => 'active']) }}"><span>Chờ / đang xử lý</span><strong>{{ number_format($aiRescueDashboard['metrics']['queued_processing']) }}</strong></a>
            <a href="{{ route('ocr-reviews.index', ['ai_status' => 'resolved']) }}"><span>Đã giải quyết</span><strong>{{ number_format($aiRescueDashboard['metrics']['resolved']) }}</strong></a>
            <a href="{{ route('ocr-reviews.index', ['ai_status' => 'non_daily']) }}"><span>Không phải Daily</span><strong>{{ number_format($aiRescueDashboard['metrics']['non_daily']) }}</strong></a>
            <a href="{{ route('ocr-reviews.index', ['ai_status' => 'human_required']) }}"><span>Cần kiểm tra</span><strong>{{ number_format($aiRescueDashboard['metrics']['human_required']) }}</strong></a>
            <a href="{{ route('ocr-reviews.index', ['ai_status' => 'failed_or_skipped']) }}"><span>Thất bại / bỏ qua</span><strong>{{ number_format($aiRescueDashboard['metrics']['failed'] + $aiRescueDashboard['metrics']['skipped']) }}</strong></a>
        </div>
        <div class="ai-token-summary">Usage đã ghi nhận: {{ number_format($aiRescueDashboard['metrics']['total_tokens']) }} token tổng · {{ number_format($aiRescueDashboard['metrics']['prompt_tokens']) }} input · {{ number_format($aiRescueDashboard['metrics']['completion_tokens']) }} output</div>
        <form method="POST" action="{{ route('ocr-reviews.ai-rescue.preview') }}" id="aiRescuePreviewForm" class="ai-reason-form">
            @csrf
            <div class="ai-reason-grid">
                @forelse($aiRescueDashboard['reason_groups'] as $group)
                    <div class="ai-reason-option">
                        <label><input class="ai-reason-checkbox" type="checkbox" name="reason_groups[]" value="{{ $group['code'] }}"> <span>{{ $group['label'] }}</span></label>
                        <a href="{{ route('ocr-reviews.index', ['workflow' => 'manual', 'reason' => $group['code']]) }}" title="Xem các ảnh thuộc nhóm này">{{ number_format($group['count']) }}</a>
                    </div>
                @empty
                    <p class="ai-empty-reasons">Hiện không có nhóm lỗi Manual để chạy AI Rescue.</p>
                @endforelse
            </div>
            <div class="ai-reason-actions">
                <span id="aiReasonSelection">Chưa chọn nhóm lỗi</span>
                <button class="btn btn-primary" type="submit" id="aiPreviewButton" disabled>OCR lại bằng AI (0 ảnh)</button>
            </div>
        </form>
    </section>
    @endif

    @unless(config('daily_photos.enabled'))
    <section class="ocr-review-stats">
        @foreach ($reviewLabels as $status => $label)
            <a href="{{ route('ocr-reviews.index', array_merge(request()->except('page'), ['review_status' => $status])) }}"
               class="ocr-review-stat status-{{ strtolower($status) }}">
                <span>{{ $label }}</span>
                <strong>{{ $reviewStatusCounts[$status] ?? 0 }}</strong>
            </a>
        @endforeach
    </section>

    @endunless
    <form method="GET" action="{{ route('ocr-reviews.index') }}" class="app-card ocr-filter-card">
        <div class="ocr-filter-heading">
            <div><strong>Bộ lọc Hậu kiểm OCR</strong><span>Các điều kiện kết hợp trên cùng tập ảnh và được giữ trong URL.</span></div>
            @if(collect($filters)->except('overview_date')->filter(fn($value) => filled($value))->isNotEmpty())
                <span class="active-filter-badge">Đang áp dụng bộ lọc</span>
            @endif
        </div>
        <div class="ocr-filter-grid">
            <label><span>Tìm kiếm</span><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Job ID, mã máy hoặc mã tin nhắn"></label>

            @if(config('daily_photos.enabled'))
            <label><span>Trạng thái nghiệp vụ</span><select name="workflow">
                <option value="">Tất cả</option>
                @foreach($workflowLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['workflow'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select></label>

            <label><span>Nguồn / lịch sử OCR</span><select name="ocr_source">
                <option value="">Tất cả</option>
                @foreach($ocrSourceLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['ocr_source'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select></label>

            <label><span>AI Rescue</span><select name="ai_status">
                <option value="">Tất cả</option>
                @foreach($aiFilterLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['ai_status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select></label>

            <label><span>Nhóm ngoại lệ</span><select name="reason">
                <option value="">Tất cả</option>
                @foreach($exceptionReasonLabels as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['reason'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select></label>

            <label><span>Sender ID / tên</span><input name="sender" value="{{ $filters['sender'] ?? '' }}" placeholder="Sender chính xác hoặc tên"></label>
            @endif

            @unless(config('daily_photos.enabled'))<label><span>Trạng thái hậu kiểm</span><select name="review_status">
                <option value="">Tất cả trạng thái hậu kiểm</option>
                @foreach ($reviewLabels as $status => $label)
                    <option value="{{ $status }}" @selected(($filters['review_status'] ?? '') === $status)>{{ $label }}</option>
                @endforeach
            </select></label>

            @endunless
            <label><span>Loại ảnh</span><select name="document_type">
                <option value="">Tất cả loại ảnh</option>
                @foreach ($typeLabels as $type => $label)
                    <option value="{{ $type }}" @selected(($filters['document_type'] ?? '') === $type)>{{ $label }}</option>
                @endforeach
            </select></label>

            <label><span>Mã máy</span><select name="machine_id">
                <option value="">Tất cả thiết bị</option>
                @foreach ($machines as $machine)
                    <option value="{{ $machine->id }}" @selected((string) ($filters['machine_id'] ?? '') === (string) $machine->id)>{{ $machine->asset_code }}</option>
                @endforeach
            </select></label>

            <label><span>Ngày gửi Zalo từ</span><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
            <label><span>Ngày gửi Zalo đến</span><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
        </div>
        <div class="ocr-filter-actions">
            <button class="btn btn-primary" type="submit">Áp dụng</button>
            <a class="btn btn-outline-secondary" href="{{ route('ocr-reviews.index') }}">Xóa bộ lọc</a>
        </div>
    </form>

    <section class="app-card ocr-overview-card">
        <div class="ocr-section-head">
            <div>
                <strong>Tổng quan ảnh hằng ngày theo máy</strong>
                <span>Ảnh đủ dữ liệu tự động được cập nhật vào Đối chiếu.</span>
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
                    <small>{{ $group['total'] }} ảnh đã nhận · {{ $group['exceptions'] }} ngoại lệ OCR</small>
                </div>
            @empty
                <div class="ocr-empty-group">Chưa có ảnh hằng ngày trong ngày đã chọn.</div>
            @endforelse
        </div>
    </section>

    <form method="POST" action="{{ route('ocr-reviews.bulk') }}" id="bulkReviewForm">
        @csrf
        @unless(config('daily_photos.enabled'))<section class="app-card ocr-bulk-bar">
            <label><input type="checkbox" id="selectAllJobs"> Chọn tất cả trang này</label>
            <span id="selectedCount">0 job được chọn</span>
            <select name="action" required>
                <option value="approve">Duyệt đúng</option>
                <option value="reject">Từ chối</option>
            </select>
            <input name="review_notes" placeholder="Ghi chú chung (không bắt buộc)">
            <button class="btn btn-primary" type="submit">Áp dụng hàng loạt</button>
        </section>

        @endunless
        <section class="app-card table-card">
            <div class="table-scroll">
                <table class="table table-modern ocr-table">
                    <thead>
                    <tr>
                        <th></th>
                        <th>Job</th>
                        <th>Loại ảnh</th>
                        <th>Dữ liệu ảnh</th>
                        <th>OCR</th>
                        <th>Mã máy</th>
                        <th>Ngày / giờ</th>
                        <th>Người gửi</th>
                        <th>Độ tin cậy</th>
                        @if(config('daily_photos.enabled'))<th>AI Rescue</th>@endif
                        <th class="sticky-action">Thao tác</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($jobs as $job)
                        <tr class="{{ $job->review_status === 'PENDING' ? 'row-pending' : '' }}">
                            <td>@unless(config('daily_photos.enabled'))<input class="job-checkbox" type="checkbox" name="job_ids[]" value="{{ $job->id }}">@endunless</td>
                            <td><strong>#{{ $job->id }}</strong></td>
                            <td>{{ $typeLabels[$job->document_type] ?? $job->document_type }}</td>
                            <td>@if(config('daily_photos.enabled') && $job->document_type === 'DAILY_TIMEMARK')
                                {{ $job->status === 'COMPLETED' ? 'Đã nhận dữ liệu ảnh' : 'Ngoại lệ / đang xử lý' }}
                            @else<span class="review-badge review-{{ strtolower($job->review_status) }}">{{ $reviewLabels[$job->review_status] ?? $job->review_status }}</span>@endif</td>
                            <td>{{ $job->status }}</td>
                            <td class="machine-code">{{ $job->asset_code ?: $job->machine?->asset_code ?: '—' }}</td>
                            <td>
                                {{ $job->extracted_date?->format('d/m/Y') ?: $job->attachment?->message?->sent_at?->format('d/m/Y') ?: '—' }}
                                <small>{{ $job->extracted_time ? substr($job->extracted_time, 0, 5) : '' }}</small>
                            </td>
                            <td>{{ $job->attachment?->message?->sender_name ?: '—' }}</td>
                            <td>{{ $job->confidence !== null ? number_format((float) $job->confidence * 100, 0).'%' : '—' }}</td>
                            @if(config('daily_photos.enabled'))
                            <td>
                                @if($job->latestAiRescueAttempt)
                                    <span class="ai-inline-status ai-{{ strtolower($job->latestAiRescueAttempt->status) }}">AI · {{ $aiResolutionLabels[$job->latestAiRescueAttempt->final_resolution] ?? $aiStatusLabels[$job->latestAiRescueAttempt->status] ?? $job->latestAiRescueAttempt->status }}</span>
                                    <small>{{ $job->latestAiRescueAttempt->processed_at?->format('d/m H:i') ?: $job->latestAiRescueAttempt->requested_at?->format('d/m H:i') }}</small>
                                @else
                                    <span class="text-muted">Chưa AI</span>
                                @endif
                            </td>
                            @endif
                            <td class="sticky-action"><a class="btn btn-sm btn-outline-primary" href="{{ route('ocr-reviews.show', $job) }}">{{ $job->document_type === 'DAILY_TIMEMARK' ? 'Sửa dữ liệu' : 'Xem' }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ config('daily_photos.enabled') ? 11 : 10 }}" class="ocr-empty">Không có ảnh phù hợp với bộ lọc hiện tại. <a href="{{ route('ocr-reviews.index') }}">Xóa bộ lọc</a></td></tr>
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
.ocr-filter-card{padding:14px;margin-bottom:16px}.ocr-filter-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.ocr-filter-heading span{display:block;margin-top:3px;color:#64748b;font-size:10px}.active-filter-badge{padding:6px 9px;border-radius:999px;background:#eef4ff;color:#2558c7!important;font-weight:800}.ocr-filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.ocr-filter-grid label{display:flex;min-width:0;flex-direction:column;gap:5px}.ocr-filter-grid label>span{color:#64748b;font-size:10px;font-weight:700}
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
.ai-rescue-dashboard{margin-bottom:16px;overflow:hidden}.ai-manual-total{padding:7px 10px;border-radius:999px;background:#eef4ff;color:#2558c7;font-size:11px;font-weight:800}.ai-metrics-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:1px;background:var(--border)}.ai-metrics-grid>a{display:flex;min-height:78px;flex-direction:column;gap:6px;padding:13px;background:#fff;color:inherit;text-decoration:none}.ai-metrics-grid>a:hover{background:#f8fbff}.ai-metrics-grid span{color:#64748b;font-size:10px}.ai-metrics-grid strong{font-size:20px}.ai-token-summary{padding:8px 14px;border-top:1px solid var(--border);background:#f8fafc;color:#64748b;font-size:10px;text-align:right}.ai-reason-form{padding:14px}.ai-reason-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.ai-reason-option{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:9px;padding:10px 12px;border:1px solid #dbe4ef;border-radius:10px;background:#f8fafc}.ai-reason-option:has(input:checked){border-color:#7ba6f8;background:#eef4ff}.ai-reason-option label{display:flex;align-items:center;gap:9px;cursor:pointer}.ai-reason-option span{font-size:11px;font-weight:700}.ai-reason-option>a{color:#2558c7;font-weight:800;text-decoration:none}.ai-empty-reasons{grid-column:1/-1;margin:0;color:#64748b}.ai-reason-actions{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:12px}.ai-reason-actions span{margin-right:auto;color:#64748b;font-size:11px}.ai-inline-status{display:inline-flex;padding:4px 7px;border-radius:999px;background:#eef2f7;font-size:9px;font-weight:800}.ai-processing{background:#e8efff;color:#2558c7}.ai-completed{background:#e9f8f1;color:#13734d}.ai-failed{background:#fff0f1;color:#b42332}
@media(max-width:1100px){.ocr-filter-grid{grid-template-columns:repeat(3,1fr)}.ocr-machine-groups{grid-template-columns:repeat(2,1fr)}.ocr-bulk-bar{grid-template-columns:1fr 1fr}}
@media(max-width:1100px){.ai-metrics-grid{grid-template-columns:repeat(3,1fr)}.ai-reason-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){.ocr-review-stats,.ocr-filter-grid,.ocr-machine-groups,.ai-reason-grid{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.ocr-review-stats,.ocr-filter-grid,.ocr-machine-groups,.ai-reason-grid,.ai-metrics-grid{grid-template-columns:1fr}.ai-reason-actions{align-items:stretch;flex-direction:column}.ai-reason-actions span{margin:0}.ai-reason-actions button{width:100%}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('selectAllJobs');
    const checkboxes = [...document.querySelectorAll('.job-checkbox')];
    const counter = document.getElementById('selectedCount');
    const form = document.getElementById('bulkReviewForm');
    if (!selectAll || !counter) return;

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

@if(config('daily_photos.enabled') && $aiRescueDashboard)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('aiRescuePreviewForm');
    const checkboxes = [...document.querySelectorAll('.ai-reason-checkbox')];
    const button = document.getElementById('aiPreviewButton');
    const selection = document.getElementById('aiReasonSelection');
    if (!form || !button || !selection) return;
    let updateTimer;
    let requestVersion = 0;
    const update = () => {
        const selected = checkboxes.filter(checkbox => checkbox.checked);
        button.disabled = selected.length === 0;
        if (!selected.length) {
            button.textContent = 'OCR lại bằng AI (0 ảnh)';
            selection.textContent = 'Chưa chọn nhóm lỗi';
            return;
        }
        button.textContent = 'Đang tính ảnh unique...';
        selection.textContent = `${selected.length} nhóm đã chọn`;
        clearTimeout(updateTimer);
        const version = ++requestVersion;
        updateTimer = setTimeout(async () => {
            const payload = new FormData();
            payload.append('_token', form.querySelector('input[name="_token"]').value);
            selected.forEach(checkbox => payload.append('reason_groups[]', checkbox.value));
            try {
                const response = await fetch('{{ route('ocr-reviews.ai-rescue.selection-count') }}', {
                    method: 'POST', body: payload, headers: {'Accept': 'application/json'},
                });
                if (!response.ok) throw new Error('count failed');
                const result = await response.json();
                if (version !== requestVersion) return;
                button.textContent = `OCR lại bằng AI (${result.unique_photos} ảnh)`;
                selection.textContent = `${selected.length} nhóm · ${result.unique_photos} ảnh unique`;
            } catch (error) {
                if (version !== requestVersion) return;
                button.textContent = 'Tiếp tục đến Preview';
                selection.textContent = `${selected.length} nhóm · server sẽ tính lại ở Preview`;
            }
        }, 180);
    };
    checkboxes.forEach(checkbox => checkbox.addEventListener('change', update));
    form.addEventListener('submit', () => {
        button.disabled = true;
        button.textContent = 'Đang tạo preview...';
    });
    update();
});
</script>
@endif
@endsection
