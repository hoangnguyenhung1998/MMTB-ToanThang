@extends('layouts.app')

@section('content')
<div class="container-fluid machine-timeline-page">
    <div class="timeline-header">
        <div>
            <div class="timeline-eyebrow">LỊCH SỬ THIẾT BỊ</div>
            <h1>{{ $machine->asset_code }}</h1>
            <p>
                {{ $machine->machine_type ?: 'Chưa có loại máy' }}
                @if ($machine->plate_no)
                    · {{ $machine->plate_no }}
                @endif
                @if ($machine->chassis_no)
                    · Số khung {{ $machine->chassis_no }}
                @endif
            </p>
        </div>

        <div class="timeline-header-actions">
            <a href="{{ route('machines.show', $machine) }}" class="btn btn-outline-secondary">
                Chi tiết máy
            </a>
            <a href="{{ route('activities.index', ['machine_id' => $machine->id]) }}" class="btn btn-primary">
                Nhật ký kỹ thuật
            </a>
        </div>
    </div>

    <div class="timeline-summary-grid">
        <div class="timeline-summary-card is-primary">
            <small>Tổng sự kiện</small>
            <strong>{{ number_format($counts['all']) }}</strong>
        </div>
        <div class="timeline-summary-card is-info">
            <small>Nghiệp vụ máy</small>
            <strong>{{ number_format($counts['operations']) }}</strong>
        </div>
        <div class="timeline-summary-card is-driver">
            <small>Thay đổi tài xế</small>
            <strong>{{ number_format($counts['drivers']) }}</strong>
        </div>
        <div class="timeline-summary-card is-warning">
            <small>Hồ sơ</small>
            <strong>{{ number_format($counts['documents']) }}</strong>
        </div>
    </div>

    <section class="timeline-filter-card">
        <form method="GET" action="{{ route('machines.timeline', $machine) }}" class="timeline-filter-grid">
            <div class="timeline-filter-field timeline-filter-search">
                <label for="timeline-q">Từ khóa</label>
                <input
                    id="timeline-q"
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="Sự kiện, tài xế, dự án..."
                    class="form-control"
                >
            </div>

            <div class="timeline-filter-field">
                <label for="timeline-type">Nhóm sự kiện</label>
                <select id="timeline-type" name="type" class="form-select">
                    <option value="">Tất cả</option>
                    <option value="system" @selected(request('type') === 'system')>Hệ thống</option>
                    <option value="status" @selected(request('type') === 'status')>Trạng thái</option>
                    <option value="handover" @selected(request('type') === 'handover')>Bàn giao</option>
                    <option value="transfer" @selected(request('type') === 'transfer')>Điều chuyển</option>
                    <option value="return" @selected(request('type') === 'return')>Trả máy</option>
                    <option value="driver" @selected(request('type') === 'driver')>Tài xế</option>
                    <option value="document" @selected(request('type') === 'document')>Hồ sơ</option>
                </select>
            </div>

            <div class="timeline-filter-field">
                <label for="timeline-from">Từ ngày</label>
                <input id="timeline-from" type="date" name="date_from"
                       value="{{ request('date_from') }}" class="form-control">
            </div>

            <div class="timeline-filter-field">
                <label for="timeline-to">Đến ngày</label>
                <input id="timeline-to" type="date" name="date_to"
                       value="{{ request('date_to') }}" class="form-control">
            </div>

            <div class="timeline-filter-actions">
                <button class="btn btn-primary" type="submit">Lọc</button>
                @if (request()->hasAny(['q', 'type', 'date_from', 'date_to']))
                    <a href="{{ route('machines.timeline', $machine) }}" class="btn btn-outline-secondary">
                        Xóa lọc
                    </a>
                @endif
            </div>
        </form>
    </section>

    <section class="timeline-panel">
        <div class="timeline-panel-head">
            <div>
                <span class="timeline-kicker">DÒNG THỜI GIAN</span>
                <h2>{{ number_format($timeline->count()) }} sự kiện</h2>
                <p>Sắp xếp từ mới nhất đến cũ nhất.</p>
            </div>
        </div>

        <div class="timeline-list">
            @forelse ($timeline as $item)
                <article class="timeline-item">
                    <div class="timeline-axis">
                        <span class="timeline-dot is-{{ $item['tone'] }}">{{ $item['icon'] }}</span>
                        @unless ($loop->last)
                            <span class="timeline-line"></span>
                        @endunless
                    </div>

                    <div class="timeline-content">
                        <div class="timeline-content-head">
                            <div>
                                <div class="timeline-title-row">
                                    <h3>{{ $item['title'] }}</h3>
                                    <span class="timeline-type-badge">{{ $item['type'] }}</span>
                                </div>

                                <time>
                                    {{ optional($item['occurred_at'])->format('d/m/Y H:i') ?: '-' }}
                                    @if ($item['occurred_at'])
                                        · {{ $item['occurred_at']->diffForHumans() }}
                                    @endif
                                </time>
                            </div>

                            @if ($item['url'])
                                <a href="{{ $item['url'] }}" class="btn btn-sm btn-outline-secondary">
                                    Mở chi tiết
                                </a>
                            @endif
                        </div>

                        <p class="timeline-description">{{ $item['description'] }}</p>

                        @if ($item['meta'] || $item['user_name'])
                            <div class="timeline-meta">
                                @if ($item['meta'])
                                    <span>{{ $item['meta'] }}</span>
                                @endif

                                @if ($item['user_name'])
                                    <span>Người thao tác: {{ $item['user_name'] }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="timeline-empty">
                    <div class="timeline-empty-icon">⌁</div>
                    <strong>Không có sự kiện phù hợp</strong>
                    <span>Thử thay đổi bộ lọc hoặc xóa từ khóa tìm kiếm.</span>
                </div>
            @endforelse
        </div>
    </section>
</div>

<style>
.machine-timeline-page{padding-bottom:32px}
.timeline-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:22px}
.timeline-eyebrow{margin-bottom:6px;color:#64748b;font-size:12px;font-weight:800;letter-spacing:.08em}
.timeline-header h1{margin:0;color:#0f172a;font-size:30px;font-weight:800;letter-spacing:-.03em}
.timeline-header p{margin:7px 0 0;color:#64748b;font-size:14px}
.timeline-header-actions{display:flex;gap:10px;align-items:center}
.timeline-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.timeline-summary-card{padding:18px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.timeline-summary-card small{display:block;color:#64748b;font-size:12px;font-weight:700}
.timeline-summary-card strong{display:block;margin-top:5px;color:#0f172a;font-size:28px;line-height:1;font-weight:800}
.timeline-summary-card.is-primary{border-top:4px solid #2563eb}
.timeline-summary-card.is-info{border-top:4px solid #0891b2}
.timeline-summary-card.is-driver{border-top:4px solid #7c3aed}
.timeline-summary-card.is-warning{border-top:4px solid #d97706}
.timeline-filter-card,.timeline-panel{margin-bottom:18px;padding:20px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 4px 16px rgba(15,23,42,.04)}
.timeline-filter-grid{display:grid;grid-template-columns:minmax(230px,1fr) 170px 155px 155px auto;gap:12px;align-items:end}
.timeline-filter-field label{display:block;margin-bottom:6px;color:#475569;font-size:12px;font-weight:700}
.timeline-filter-actions{display:flex;gap:8px}
.timeline-panel-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px}
.timeline-kicker{display:inline-flex;padding:4px 8px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:10px;font-weight:800;letter-spacing:.05em}
.timeline-panel-head h2{margin:6px 0 0;color:#0f172a;font-size:20px;font-weight:800}
.timeline-panel-head p{margin:4px 0 0;color:#64748b;font-size:13px}
.timeline-list{display:flex;flex-direction:column}
.timeline-item{display:grid;grid-template-columns:48px minmax(0,1fr);gap:14px}
.timeline-axis{position:relative;display:flex;flex-direction:column;align-items:center}
.timeline-dot{position:relative;z-index:2;display:flex;width:38px;height:38px;flex:0 0 38px;align-items:center;justify-content:center;border-radius:12px;font-size:16px;font-weight:900}
.timeline-dot.is-primary{background:#dbeafe;color:#1d4ed8}
.timeline-dot.is-info{background:#cffafe;color:#0e7490}
.timeline-dot.is-warning{background:#fef3c7;color:#a16207}
.timeline-dot.is-danger{background:#ffe4e6;color:#be123c}
.timeline-line{width:2px;min-height:78px;flex:1;background:#e2e8f0}
.timeline-content{margin-bottom:14px;padding:16px;border:1px solid #e2e8f0;border-radius:14px;background:#fff;transition:.15s ease}
.timeline-content:hover{border-color:#cbd5e1;box-shadow:0 5px 18px rgba(15,23,42,.06)}
.timeline-content-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}
.timeline-title-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.timeline-title-row h3{margin:0;color:#0f172a;font-size:15px;font-weight:800}
.timeline-type-badge{padding:2px 7px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase}
.timeline-content time{display:block;margin-top:4px;color:#94a3b8;font-size:11px}
.timeline-description{margin:10px 0 0;color:#475569;font-size:13px;line-height:1.55}
.timeline-meta{display:flex;flex-wrap:wrap;gap:7px 16px;margin-top:10px;color:#64748b;font-size:11px}
.timeline-meta span+span{position:relative}
.timeline-meta span+span:before{content:"";position:absolute;left:-9px;top:50%;width:3px;height:3px;border-radius:50%;background:#cbd5e1;transform:translateY(-50%)}
.timeline-empty{display:flex;min-height:200px;flex-direction:column;align-items:center;justify-content:center;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;text-align:center}
.timeline-empty-icon{display:flex;width:50px;height:50px;align-items:center;justify-content:center;margin-bottom:10px;border-radius:50%;background:#e2e8f0;color:#475569;font-size:23px}
.timeline-empty strong{color:#0f172a}
.timeline-empty span{margin-top:5px;color:#64748b;font-size:13px}
@media(max-width:1100px){
    .timeline-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .timeline-filter-grid{grid-template-columns:1fr 1fr}
    .timeline-filter-search{grid-column:1/-1}
}
@media(max-width:700px){
    .timeline-header{flex-direction:column}
    .timeline-header-actions{width:100%;flex-wrap:wrap}
    .timeline-summary-grid,.timeline-filter-grid{grid-template-columns:1fr}
    .timeline-filter-search{grid-column:auto}
    .timeline-content-head{flex-direction:column}
}
</style>
@endsection
