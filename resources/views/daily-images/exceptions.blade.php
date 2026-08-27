@extends('layouts.app')

@section('content')
<div class="page-shell daily-exceptions">
    <nav class="daily-image-tabs" aria-label="Kho ảnh hằng ngày">
        <a href="{{ route('daily-images.index') }}">Kho ảnh & xuất ZIP</a>
        <a class="active" href="{{ route('daily-images.exceptions', request()->query()) }}">Ngoại lệ tự động</a>
    </nav>

    <header class="page-header">
        <div>
            <div class="page-eyebrow">PHASE 15.5</div>
            <h1 class="page-title">Trung tâm kiểm soát ngoại lệ</h1>
            <p class="page-subtitle">Hệ thống tự theo dõi máy đang được phân công; anh chỉ cần xử lý trường hợp chưa thể kết luận.</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('ocr-reviews.index', ['review_status' => 'PENDING', 'document_type' => 'DAILY_TIMEMARK']) }}">Mở Hậu kiểm OCR</a>
    </header>

    <section class="exception-stats">
        <div><span>Máy/ngày theo dõi</span><strong>{{ number_format($summary['tracked']) }}</strong></div>
        <div class="ok"><span>Hoàn thành tự động</span><strong>{{ number_format($summary['automatic']) }}</strong></div>
        <div class="warn"><span>Cần xử lý</span><strong>{{ number_format($summary['exceptions']) }}</strong></div>
        <div class="danger"><span>Chưa có ảnh</span><strong>{{ number_format($summary['no_images']) }}</strong></div>
        <div><span>Chưa xác định máy/giờ</span><strong>{{ number_format($summary['unidentified']) }}</strong></div>
    </section>

    <form method="GET" action="{{ route('daily-images.exceptions') }}" class="app-card exception-filter">
        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" title="Từ ngày">
        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" title="Đến ngày">
        <select name="machine_id"><option value="">Tất cả máy</option>@foreach($machines as $machine)<option value="{{ $machine->id }}" @selected((string)($filters['machine_id'] ?? '') === (string)$machine->id)>{{ $machine->asset_code }}</option>@endforeach</select>
        <select name="command_center_id"><option value="">Tất cả BCH</option>@foreach($commandCenters as $bch)<option value="{{ $bch->id }}" @selected((string)($filters['command_center_id'] ?? '') === (string)$bch->id)>{{ $bch->name }}</option>@endforeach</select>
        <select name="exception_status">
            <option value="EXCEPTIONS" @selected(($filters['exception_status'] ?? '') === 'EXCEPTIONS')>Tất cả ngoại lệ</option>
            <option value="NO_IMAGES" @selected(($filters['exception_status'] ?? '') === 'NO_IMAGES')>Chưa có ảnh</option>
            <option value="PENDING_REVIEW" @selected(($filters['exception_status'] ?? '') === 'PENDING_REVIEW')>Chờ hậu kiểm OCR</option>
            <option value="MISSING_MARK" @selected(($filters['exception_status'] ?? '') === 'MISSING_MARK')>Thiếu một đầu ca</option>
            <option value="DUPLICATE_TIME" @selected(($filters['exception_status'] ?? '') === 'DUPLICATE_TIME')>Trùng giờ</option>
            <option value="CTMS_PENDING" @selected(($filters['exception_status'] ?? '') === 'CTMS_PENDING')>2 ảnh – chờ CTMS</option>
            <option value="AUTO_COMPLETE" @selected(($filters['exception_status'] ?? '') === 'AUTO_COMPLETE')>Hoàn thành tự động</option>
        </select>
        <button class="btn btn-primary" type="submit">Lọc</button>
        <a class="btn btn-outline-secondary" href="{{ route('daily-images.exceptions') }}">Hôm nay</a>
    </form>

    <section class="exception-list">
        @forelse($groups as $group)
            <article class="app-card exception-row status-{{ strtolower($group['status']) }}">
                <div class="exception-identity"><strong>{{ $group['machine_code'] }}</strong><span>{{ $group['date_label'] }} · {{ $group['command_center'] }}</span></div>
                <div class="exception-count"><strong>{{ $group['approved_count'] }}</strong><span>ảnh hợp lệ</span></div>
                <div class="exception-count"><strong>{{ $group['pending_count'] }}</strong><span>chờ duyệt</span></div>
                <span class="exception-badge">{{ $group['status_label'] }}</span>
                <div class="exception-actions">
                    @if($group['approved_count'] > 0)
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('daily-images.index', ['date_from' => $group['date'], 'date_to' => $group['date'], 'machine_id' => $group['machine_id']]) }}">Xem ảnh</a>
                    @endif
                    @if($group['pending_count'] > 0)
                        <a class="btn btn-sm btn-primary" href="{{ route('ocr-reviews.index', ['review_status' => 'PENDING', 'document_type' => 'DAILY_TIMEMARK', 'machine_id' => $group['machine_id']]) }}">Hậu kiểm</a>
                    @endif
                </div>
            </article>
        @empty
            <div class="app-card exception-empty">Không có máy/ngày phù hợp. Nếu đang lọc ngoại lệ, toàn bộ dữ liệu trong phạm vi này đã hoàn thành tự động.</div>
        @endforelse
    </section>

    <div class="ocr-pagination">{{ $groups->links() }}</div>
</div>

<style>
.daily-image-tabs{display:flex;gap:8px;margin-bottom:14px}.daily-image-tabs a{padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#64748b;text-decoration:none;font-size:12px;font-weight:800}.daily-image-tabs a.active{border-color:#2f67ea;background:#eef4ff;color:#2456b8}
.exception-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:11px;margin-bottom:15px}.exception-stats>div{padding:14px 16px;border:1px solid var(--border);border-radius:14px;background:#fff}.exception-stats span,.exception-count span,.exception-identity span{display:block;color:#64748b;font-size:10px;font-weight:700}.exception-stats strong{display:block;margin-top:6px;font-size:22px}.exception-stats .ok{border-color:#9ed9c4;background:#f0fbf7}.exception-stats .warn{border-color:#f2ca73;background:#fffaf0}.exception-stats .danger{border-color:#f0a7ad;background:#fff5f5}
.exception-filter{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr)) auto auto;gap:9px;padding:13px;margin-bottom:14px}.exception-list{display:grid;gap:9px}.exception-row{display:grid;grid-template-columns:minmax(200px,1fr) 90px 90px minmax(170px,.7fr) auto;align-items:center;gap:13px;padding:13px 15px;border-left:4px solid #f0bd58}.exception-row.status-auto_complete{border-left-color:#58bd92}.exception-row.status-no_images,.exception-row.status-pending_review{border-left-color:#e36c75}.exception-identity span{margin-top:4px}.exception-count{text-align:center}.exception-count strong{font-size:19px}.exception-badge{justify-self:start;padding:6px 9px;border-radius:999px;background:#fff0d8;color:#965000;font-size:10px;font-weight:800}.status-auto_complete .exception-badge{background:#def7ec;color:#087047}.status-no_images .exception-badge,.status-pending_review .exception-badge{background:#fff0f1;color:#b42332}.exception-actions{display:flex;gap:6px;justify-content:flex-end}.exception-empty{padding:45px;text-align:center;color:#64748b}
@media(max-width:1100px){.exception-stats{grid-template-columns:repeat(3,1fr)}.exception-filter{grid-template-columns:repeat(3,1fr)}.exception-row{grid-template-columns:1fr 80px 80px}.exception-badge,.exception-actions{justify-self:start}}
@media(max-width:650px){.exception-stats,.exception-filter{grid-template-columns:1fr 1fr}.exception-row{grid-template-columns:1fr 1fr}.exception-identity,.exception-actions{grid-column:1/-1}}
</style>
@endsection
