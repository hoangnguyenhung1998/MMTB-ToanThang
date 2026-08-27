@extends('layouts.app')

@section('content')
<div class="page-shell daily-archive">
    <nav class="daily-image-tabs" aria-label="Kho ảnh hằng ngày">
        <a class="active" href="{{ route('daily-images.index', request()->query()) }}">Kho ảnh & xuất ZIP</a>
        <a href="{{ route('daily-images.exceptions') }}">Ngoại lệ tự động</a>
    </nav>
    <header class="page-header">
        <div>
            <div class="page-eyebrow">PHASE 15.4</div>
            <h1 class="page-title">Kho ảnh đầu ca – cuối ca</h1>
            <p class="page-subtitle">Chỉ sử dụng ảnh hằng ngày đã hậu kiểm; ghép lần lượt 1–2, 3–4, 5–6 và 7–8.</p>
        </div>
        <form method="GET" action="{{ route('daily-images.export') }}">
            @foreach ($filters as $key => $value)
                @if ($key !== 'page' && filled($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
            @endforeach
            <button class="btn btn-primary" type="submit">Xuất ZIP ảnh</button>
        </form>
    </header>

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <section class="archive-stats">
        <div><span>Máy/ngày</span><strong>{{ number_format($summary['groups']) }}</strong></div>
        <div><span>Tổng ảnh</span><strong>{{ number_format($summary['images']) }}</strong></div>
        <div class="ok"><span>Đủ cặp</span><strong>{{ number_format($summary['complete']) }}</strong></div>
        <div class="warn"><span>Cần xử lý</span><strong>{{ number_format($summary['incomplete']) }}</strong></div>
    </section>

    <form method="GET" action="{{ route('daily-images.index') }}" class="app-card archive-filter">
        <input type="month" name="month" value="{{ $filters['month'] ?? '' }}">
        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" title="Từ ngày">
        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" title="Đến ngày">
        <select name="machine_id">
            <option value="">Tất cả máy</option>
            @foreach ($machines as $machine)
                <option value="{{ $machine->id }}" @selected((string)($filters['machine_id'] ?? '') === (string)$machine->id)>{{ $machine->asset_code }}</option>
            @endforeach
        </select>
        <select name="command_center_id">
            <option value="">Tất cả BCH</option>
            @foreach ($commandCenters as $bch)
                <option value="{{ $bch->id }}" @selected((string)($filters['command_center_id'] ?? '') === (string)$bch->id)>{{ $bch->name }}</option>
            @endforeach
        </select>
        <select name="completeness">
            <option value="">Tất cả tình trạng</option>
            <option value="complete" @selected(($filters['completeness'] ?? '') === 'complete')>Đủ cặp</option>
            <option value="incomplete" @selected(($filters['completeness'] ?? '') === 'incomplete')>Thiếu/trùng ảnh</option>
        </select>
        <button class="btn btn-primary" type="submit">Lọc</button>
        <a class="btn btn-outline-secondary" href="{{ route('daily-images.index') }}">Xóa lọc</a>
    </form>

    <section class="archive-groups">
        @forelse ($groups as $group)
            <article class="app-card archive-group {{ $group['is_complete'] ? '' : 'incomplete' }}">
                <header>
                    <div>
                        <strong>{{ $group['machine_code'] }}</strong>
                        <span>{{ $group['date_label'] }} · {{ $group['command_center'] }}</span>
                    </div>
                    <span class="archive-badge {{ $group['is_complete'] ? 'complete' : 'incomplete' }}">
                        {{ $group['is_complete'] ? $group['session_count'].' ca đủ cặp' : ($group['has_duplicate_times'] ? 'Trùng giờ' : 'Thiếu một đầu ca') }}
                    </span>
                </header>
                <div class="session-list">
                    @foreach ($group['sessions'] as $session)
                        <div class="session-row {{ $session['end'] ? '' : 'missing' }}">
                            <b>Ca {{ $session['number'] }}</b>
                            @foreach ([['label' => 'Đầu ca', 'job' => $session['start']], ['label' => 'Cuối ca', 'job' => $session['end']]] as $mark)
                                <div class="time-mark {{ $mark['job'] ? '' : 'empty' }}">
                                    @if ($mark['job'])
                                        <a href="{{ route('ocr-reviews.show', $mark['job']) }}" title="Mở hậu kiểm OCR job #{{ $mark['job']->id }}">
                                            <img src="{{ route('ocr-reviews.image', $mark['job']) }}" alt="{{ $mark['label'] }} {{ substr($mark['job']->extracted_time, 0, 5) }}" loading="lazy">
                                        </a>
                                        <span>{{ $mark['label'] }} <strong>{{ substr($mark['job']->extracted_time, 0, 5) }}</strong></span>
                                    @else
                                        <span>Thiếu {{ strtolower($mark['label']) }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </article>
        @empty
            <div class="app-card archive-empty">Chưa có ảnh hằng ngày đã duyệt theo bộ lọc.</div>
        @endforelse
    </section>

    <div class="ocr-pagination">{{ $groups->links() }}</div>
</div>

<style>
.daily-image-tabs{display:flex;gap:8px;margin-bottom:14px}.daily-image-tabs a{padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#64748b;text-decoration:none;font-size:12px;font-weight:800}.daily-image-tabs a.active{border-color:#2f67ea;background:#eef4ff;color:#2456b8}
.archive-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}.archive-stats>div{padding:15px 17px;border:1px solid var(--border);border-radius:14px;background:#fff}.archive-stats span{display:block;color:#64748b;font-size:11px;font-weight:700}.archive-stats strong{display:block;margin-top:7px;font-size:23px}.archive-stats .ok{border-color:#9ed9c4;background:#f0fbf7}.archive-stats .warn{border-color:#f2ca73;background:#fffaf0}
.archive-filter{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr)) auto auto;gap:9px;padding:14px;margin-bottom:16px}.archive-groups{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.archive-group{overflow:hidden}.archive-group.incomplete{border-color:#f0bd58}.archive-group>header{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:13px 15px;border-bottom:1px solid var(--border)}.archive-group header span{display:block;margin-top:3px;color:#64748b;font-size:11px}.archive-badge{padding:5px 9px;border-radius:999px;font-size:10px!important;font-weight:800}.archive-badge.complete{color:#087047;background:#def7ec}.archive-badge.incomplete{color:#a05200;background:#fff0d8}
.session-list{padding:10px}.session-row{display:grid;grid-template-columns:52px 1fr 1fr;gap:8px;align-items:stretch;margin-bottom:8px}.session-row:last-child{margin-bottom:0}.session-row>b{display:flex;align-items:center;justify-content:center;border-radius:9px;background:#eef4ff;color:#2456b8;font-size:11px}.time-mark{display:grid;grid-template-columns:72px 1fr;align-items:center;gap:8px;padding:6px;border:1px solid #dbe4ef;border-radius:10px}.time-mark img{width:72px;height:52px;object-fit:cover;border-radius:7px}.time-mark span{font-size:11px;color:#64748b}.time-mark span strong{color:#0f172a}.time-mark.empty{display:flex;min-height:66px;justify-content:center;background:#fff8ed;border-style:dashed;border-color:#efbf69}.archive-empty{grid-column:1/-1;padding:50px;text-align:center;color:#94a3b8}
@media(max-width:1200px){.archive-filter{grid-template-columns:repeat(4,1fr)}.archive-groups{grid-template-columns:1fr}}@media(max-width:700px){.archive-stats,.archive-filter{grid-template-columns:repeat(2,1fr)}.session-row{grid-template-columns:1fr}.time-mark{grid-template-columns:64px 1fr}}
</style>
@endsection
