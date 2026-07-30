@extends('layouts.app')

@section('content')
<div class="global-search-page">
    <div class="global-search-page-head">
        <div>
            <div class="global-search-eyebrow">TÌM KIẾM TOÀN HỆ THỐNG</div>
            <h1>Kết quả tìm kiếm</h1>
            @if (mb_strlen($query) >= 2)
                <p>Tìm thấy {{ number_format($total) }} kết quả cho “{{ $query }}”.</p>
            @else
                <p>Nhập ít nhất 2 ký tự để tìm thiết bị, tài xế, dự án hoặc ban chỉ huy.</p>
            @endif
        </div>
    </div>

    <form method="GET" action="{{ route('global-search.index') }}" class="global-search-page-form">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
        <input name="q" value="{{ $query }}" autofocus
               placeholder="Mã máy, số khung, biển số, tài xế, dự án...">
        <button type="submit">Tìm kiếm</button>
    </form>

    @php
        $sections = [
            'machines' => ['title' => 'Thiết bị', 'icon' => 'M', 'empty' => 'Không tìm thấy thiết bị phù hợp.'],
            'drivers' => ['title' => 'Tài xế', 'icon' => 'T', 'empty' => 'Không tìm thấy tài xế phù hợp.'],
            'projects' => ['title' => 'Dự án', 'icon' => 'D', 'empty' => 'Không tìm thấy dự án phù hợp.'],
            'command_centers' => ['title' => 'Ban chỉ huy', 'icon' => 'B', 'empty' => 'Không tìm thấy ban chỉ huy phù hợp.'],
        ];
    @endphp

    @if (mb_strlen($query) >= 2)
        <div class="global-search-sections">
            @foreach ($sections as $key => $section)
                <section class="global-search-section">
                    <div class="global-search-section-head">
                        <div>
                            <span class="global-search-section-icon">{{ $section['icon'] }}</span>
                            <h2>{{ $section['title'] }}</h2>
                        </div>
                        <span>{{ count($groups[$key]) }} kết quả</span>
                    </div>

                    <div class="global-search-result-list">
                        @forelse ($groups[$key] as $item)
                            <a href="{{ $item['url'] }}" class="global-search-result">
                                <span class="global-search-result-mark">{{ $section['icon'] }}</span>
                                <span class="global-search-result-copy">
                                    <strong>{{ $item['title'] }}</strong>
                                    @if ($item['subtitle'])
                                        <small>{{ $item['subtitle'] }}</small>
                                    @endif
                                    @if ($item['meta'])
                                        <em>{{ $item['meta'] }}</em>
                                    @endif
                                </span>
                                <span class="global-search-result-arrow">→</span>
                            </a>
                        @empty
                            <div class="global-search-empty">{{ $section['empty'] }}</div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>

<style>
.global-search-page{padding-bottom:32px}.global-search-eyebrow{color:#64748b;font-size:12px;font-weight:800;letter-spacing:.08em}.global-search-page-head h1{margin:6px 0 0;color:#0f172a;font-size:30px;font-weight:800;letter-spacing:-.03em}.global-search-page-head p{margin:7px 0 0;color:#64748b}.global-search-page-form{display:flex;align-items:center;gap:12px;margin-top:22px;padding:10px 12px 10px 16px;border:1px solid #cbd5e1;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05)}.global-search-page-form svg{width:21px;height:21px;fill:none;stroke:#64748b;stroke-width:2}.global-search-page-form input{min-width:0;flex:1;border:0;outline:0;background:transparent;color:#0f172a;font-size:15px}.global-search-page-form button{border:0;border-radius:10px;background:#2563eb;color:#fff;padding:10px 18px;font-weight:700}.global-search-sections{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:22px}.global-search-section{overflow:hidden;border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.04)}.global-search-section-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #e2e8f0}.global-search-section-head>div{display:flex;align-items:center;gap:10px}.global-search-section-head h2{margin:0;font-size:16px;font-weight:800}.global-search-section-head>span{color:#64748b;font-size:12px}.global-search-section-icon,.global-search-result-mark{display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:#e8efff;color:#2558c7;font-weight:800}.global-search-section-icon{width:32px;height:32px}.global-search-result-list{display:flex;flex-direction:column}.global-search-result{display:grid;grid-template-columns:38px minmax(0,1fr) 24px;align-items:center;gap:12px;padding:13px 17px;border-bottom:1px solid #eef2f7;color:#0f172a;text-decoration:none}.global-search-result:last-child{border-bottom:0}.global-search-result:hover{background:#f8fafc;color:#0f172a}.global-search-result-mark{width:36px;height:36px}.global-search-result-copy{display:flex;min-width:0;flex-direction:column}.global-search-result-copy strong{font-size:14px}.global-search-result-copy small{margin-top:2px;overflow:hidden;color:#64748b;font-size:12px;text-overflow:ellipsis;white-space:nowrap}.global-search-result-copy em{margin-top:3px;color:#2563eb;font-size:11px;font-style:normal;font-weight:700}.global-search-result-arrow{color:#94a3b8;font-size:18px}.global-search-empty{padding:24px;color:#94a3b8;font-size:13px;text-align:center}@media(max-width:991.98px){.global-search-sections{grid-template-columns:1fr}}@media(max-width:575.98px){.global-search-page-head h1{font-size:25px}.global-search-page-form{flex-wrap:wrap}.global-search-page-form button{width:100%}}
</style>
@endsection
