@extends('layouts.app')

@section('content')
@php
    $categoryLabels = [
        'waiting_handover' => 'Chờ bàn giao',
        'returned_not_synced' => 'Trả chưa đồng bộ',
        'missing_gps' => 'Thiếu GPS',
        'missing_driver' => 'Thiếu tài xế',
        'expired_document' => 'Hồ sơ hết hạn',
        'expiring_document' => 'Hồ sơ sắp hết hạn',
        'ocr_capacity' => 'Công suất OCR',
    ];
@endphp

<div class="container-fluid notification-center-page">
    <div class="notification-center-header">
        <div>
            <div class="notification-center-eyebrow">TRUNG TÂM THÔNG BÁO</div>
            <h1>Cảnh báo cần xử lý</h1>
            <p>Tổng hợp tự động từ dữ liệu máy, tài xế, hồ sơ và hệ thống OCR.</p>
        </div>

        <div class="notification-center-header-actions">
            <a href="{{ route('operation-center.index') }}" class="btn btn-outline-secondary">
                Trung tâm vận hành
            </a>

            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        Đánh dấu tất cả đã đọc
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="notification-summary-grid">
        <div class="notification-summary-card is-danger">
            <span class="notification-summary-icon">!</span>
            <span class="notification-summary-copy">
                <small>Chưa đọc</small>
                <strong>{{ number_format($unreadCount) }}</strong>
            </span>
        </div>

        <div class="notification-summary-card is-primary">
            <span class="notification-summary-icon">🔔</span>
            <span class="notification-summary-copy">
                <small>Tổng thông báo</small>
                <strong>{{ number_format($notifications->total()) }}</strong>
            </span>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <section class="notification-filter-card">
        <div class="notification-section-head">
            <div>
                <span class="notification-section-kicker is-primary">Bộ lọc</span>
                <h2>Tìm thông báo</h2>
                <p>Lọc theo trạng thái, loại cảnh báo hoặc từ khóa.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('notifications.index') }}" class="notification-filter-grid">
            <div class="notification-filter-field notification-filter-search">
                <label for="notification-q">Từ khóa</label>
                <input
                    id="notification-q"
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="Mã máy, tài xế hoặc nội dung..."
                    class="form-control"
                >
            </div>

            <div class="notification-filter-field">
                <label for="notification-status">Trạng thái</label>
                <select id="notification-status" name="status" class="form-select">
                    <option value="">Tất cả</option>
                    <option value="unread" @selected(request('status') === 'unread')>Chưa đọc</option>
                    <option value="read" @selected(request('status') === 'read')>Đã đọc</option>
                </select>
            </div>

            <div class="notification-filter-field">
                <label for="notification-category">Loại cảnh báo</label>
                <select id="notification-category" name="category" class="form-select">
                    <option value="">Tất cả</option>
                    @foreach ($categoryLabels as $value => $label)
                        <option value="{{ $value }}" @selected(request('category') === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="notification-filter-actions">
                <button type="submit" class="btn btn-primary">Lọc thông báo</button>

                @if (request()->hasAny(['q', 'status', 'category']))
                    <a href="{{ route('notifications.index') }}" class="btn btn-outline-secondary">
                        Xóa lọc
                    </a>
                @endif
            </div>
        </form>
    </section>

    <section class="notification-list-section">
        <div class="notification-section-head">
            <div>
                <span class="notification-section-kicker is-warning">Cảnh báo hệ thống</span>
                <h2>Danh sách thông báo</h2>
                <p>Ưu tiên xử lý các thông báo chưa đọc và cảnh báo màu đỏ.</p>
            </div>

            <span class="notification-section-count">{{ number_format($notifications->total()) }}</span>
        </div>

        <div class="notification-task-list">
            @forelse ($notifications as $notification)
                @php
                    $data = $notification->data;
                    $level = $data['level'] ?? 'info';
                    $tone = match ($level) {
                        'danger' => 'danger',
                        'warning' => 'warning',
                        default => 'primary',
                    };
                    $symbol = match ($level) {
                        'danger' => '!',
                        'warning' => '◷',
                        default => 'i',
                    };
                @endphp

                <article class="notification-task-card {{ $notification->read_at ? 'is-read' : 'is-unread' }}">
                    <div class="notification-task-symbol is-{{ $tone }}">{{ $symbol }}</div>

                    <div class="notification-task-main">
                        <div class="notification-task-title-row">
                            <strong class="notification-task-title">
                                {{ $data['title'] ?? 'Thông báo' }}
                            </strong>

                            @if (!$notification->read_at)
                                <span class="notification-unread-badge">Mới</span>
                            @endif

                            @if (!empty($data['category']))
                                <span class="notification-category-badge">
                                    {{ $categoryLabels[$data['category']] ?? $data['category'] }}
                                </span>
                            @endif
                        </div>

                        <p class="notification-task-message">{{ $data['message'] ?? '' }}</p>

                        <div class="notification-task-meta">
                            @if (!empty($data['asset_code']))
                                <span>Mã máy: {{ $data['asset_code'] }}</span>
                            @endif

                            @if (!empty($data['driver_name']))
                                <span>Tài xế: {{ $data['driver_name'] }}</span>
                            @endif

                            <span>{{ $notification->created_at->diffForHumans() }}</span>

                            @if ($notification->read_at)
                                <span>Đã đọc {{ $notification->read_at->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="notification-task-actions">
                        <form method="POST" action="{{ route('notifications.read', $notification) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">
                                {{ $notification->read_at ? 'Mở chi tiết' : 'Xem và đánh dấu đã đọc' }}
                            </button>
                        </form>

                        <form
                            method="POST"
                            action="{{ route('notifications.destroy', $notification) }}"
                            onsubmit="return confirm('Xóa thông báo này?')"
                        >
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">Xóa</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="notification-empty">
                    <div class="notification-empty-icon">✓</div>
                    <strong>Không có thông báo</strong>
                    <span>Hiện tại không có cảnh báo nào phù hợp với bộ lọc.</span>
                </div>
            @endforelse
        </div>

        @if ($notifications->hasPages())
            <div class="notification-pagination">
                {{ $notifications->links() }}
            </div>
        @endif
    </section>
</div>

<style>
.notification-center-page{padding-bottom:32px}
.notification-center-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:22px}
.notification-center-eyebrow{margin-bottom:6px;color:#64748b;font-size:12px;font-weight:800;letter-spacing:.08em}
.notification-center-header h1{margin:0;color:#0f172a;font-size:30px;font-weight:800;letter-spacing:-.03em}
.notification-center-header p{margin:7px 0 0;color:#64748b;font-size:14px}
.notification-center-header-actions{display:flex;align-items:center;gap:10px}
.notification-center-header-actions form{margin:0}
.notification-summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:18px}
.notification-summary-card{display:flex;align-items:center;gap:14px;min-height:96px;padding:18px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.notification-summary-card.is-danger{border-color:#fecdd3;background:#fff7f8}
.notification-summary-card.is-primary{border-color:#bfdbfe;background:#f8fbff}
.notification-summary-icon{display:flex;width:46px;height:46px;align-items:center;justify-content:center;border-radius:13px;background:#f1f5f9;color:#334155;font-size:20px;font-weight:900}
.notification-summary-card.is-danger .notification-summary-icon{background:#ffe4e6;color:#be123c}
.notification-summary-card.is-primary .notification-summary-icon{background:#dbeafe;color:#1d4ed8}
.notification-summary-copy{display:flex;flex-direction:column}
.notification-summary-copy small{color:#64748b;font-size:12px;font-weight:700}
.notification-summary-copy strong{margin-top:2px;color:#0f172a;font-size:28px;line-height:1;font-weight:800}
.notification-filter-card,.notification-list-section{margin-bottom:18px;padding:20px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.notification-section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:18px}
.notification-section-head h2{margin:4px 0 0;color:#0f172a;font-size:20px;font-weight:800}
.notification-section-head p{margin:5px 0 0;color:#64748b;font-size:13px}
.notification-section-kicker{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}
.notification-section-kicker.is-primary{background:#dbeafe;color:#1d4ed8}
.notification-section-kicker.is-warning{background:#fef3c7;color:#a16207}
.notification-section-count{display:inline-flex;min-width:40px;height:40px;align-items:center;justify-content:center;padding:0 12px;border-radius:12px;background:#f1f5f9;color:#0f172a;font-weight:800}
.notification-filter-grid{display:grid;grid-template-columns:minmax(240px,1fr) 170px 220px auto;gap:12px;align-items:end}
.notification-filter-field label{display:block;margin-bottom:6px;color:#475569;font-size:12px;font-weight:700}
.notification-filter-actions{display:flex;gap:8px;align-items:center}
.notification-task-list{display:flex;flex-direction:column;gap:10px}
.notification-task-card{display:flex;align-items:center;gap:14px;padding:15px;border:1px solid #e2e8f0;border-radius:14px;background:#fff;transition:.15s ease}
.notification-task-card:hover{border-color:#cbd5e1;box-shadow:0 5px 18px rgba(15,23,42,.06)}
.notification-task-card.is-unread{border-left:4px solid #2563eb;background:#fbfdff}
.notification-task-card.is-read{opacity:.72;background:#f8fafc}
.notification-task-symbol{display:flex;width:42px;height:42px;flex:0 0 42px;align-items:center;justify-content:center;border-radius:12px;font-size:18px;font-weight:900}
.notification-task-symbol.is-danger{background:#ffe4e6;color:#be123c}
.notification-task-symbol.is-warning{background:#fef3c7;color:#a16207}
.notification-task-symbol.is-primary{background:#dbeafe;color:#1d4ed8}
.notification-task-main{min-width:0;flex:1}
.notification-task-title-row{display:flex;flex-wrap:wrap;align-items:center;gap:7px}
.notification-task-title{color:#0f172a;font-size:15px}
.notification-unread-badge{padding:2px 7px;border-radius:999px;background:#2563eb;color:#fff;font-size:10px;font-weight:800}
.notification-category-badge{padding:2px 7px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:10px;font-weight:700}
.notification-task-message{margin:5px 0 0;color:#475569;font-size:13px;line-height:1.5}
.notification-task-meta{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:7px;color:#94a3b8;font-size:11px}
.notification-task-meta span{position:relative}
.notification-task-meta span+span:before{content:"";position:absolute;left:-8px;top:50%;width:3px;height:3px;border-radius:50%;background:#cbd5e1;transform:translateY(-50%)}
.notification-task-actions{display:flex;align-items:center;gap:8px}
.notification-task-actions form{margin:0}
.notification-empty{display:flex;min-height:180px;flex-direction:column;align-items:center;justify-content:center;padding:24px;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;text-align:center}
.notification-empty-icon{display:flex;width:48px;height:48px;align-items:center;justify-content:center;margin-bottom:10px;border-radius:50%;background:#dcfce7;color:#15803d;font-size:24px;font-weight:900}
.notification-empty strong{color:#0f172a;font-size:16px}
.notification-empty span{margin-top:4px;color:#64748b;font-size:13px}
.notification-pagination{margin-top:18px}
@media(max-width:992px){
    .notification-center-header{flex-direction:column}
    .notification-filter-grid{grid-template-columns:1fr 1fr}
    .notification-filter-search{grid-column:1/-1}
    .notification-task-card{align-items:flex-start;flex-wrap:wrap}
    .notification-task-main{min-width:calc(100% - 60px)}
    .notification-task-actions{width:100%;padding-left:56px}
}
@media(max-width:640px){
    .notification-summary-grid{grid-template-columns:1fr}
    .notification-filter-grid{grid-template-columns:1fr}
    .notification-filter-search{grid-column:auto}
    .notification-center-header-actions{width:100%;flex-wrap:wrap}
    .notification-task-actions{padding-left:0;flex-wrap:wrap}
}
</style>
@endsection
