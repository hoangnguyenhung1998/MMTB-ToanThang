@once
<style>
.dossier{--d-line:#c9d3e0;--d-muted:#4d6076;--d-blue:#1256ac;--d-panel:#fff;max-width:1440px;margin:0 auto;padding:clamp(12px,2vw,26px);color:#152338}
.dossier h1,.dossier h2,.dossier h3{margin:0;font-weight:700;color:#132238}.dossier h1{font-size:clamp(22px,3vw,30px)}.dossier h2{font-size:19px}.dossier h3{font-size:16px}
.dossier a{color:var(--d-blue);text-underline-offset:3px}.dossier a:focus-visible,.dossier button:focus-visible,.dossier summary:focus-visible{outline:2px solid #196bce;outline-offset:3px}
.dossier .d-header,.dossier .d-toolbar,.dossier .d-filter,.dossier .d-actions{display:flex;gap:10px 16px;align-items:center;flex-wrap:wrap}.dossier .d-header,.dossier .d-toolbar{justify-content:space-between}
.dossier .d-nav{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:8px;margin-bottom:12px;border:1px solid var(--d-line);border-radius:8px;background:#f1f5fa;font-size:13px}
.dossier .d-nav-action{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:8px 12px;border:1px solid #c4d0df;border-radius:6px;background:#fff;color:#23476d;font-weight:650;text-decoration:none;cursor:pointer}
.dossier .d-nav-action svg{width:18px;height:18px;flex:none}
.dossier .d-nav-action:hover,.dossier .d-nav-settings[open]>summary{border-color:#6f99cc;background:#e7f0fc;color:#144b8a}
.dossier .d-nav-action.is-current{border-color:var(--d-blue);background:var(--d-blue);color:#fff}
.dossier .d-nav-action.is-current:hover{background:#104883}
.dossier .d-nav-settings{position:relative;margin:0 0 0 auto}
.dossier .d-nav-settings>summary{list-style:none}
.dossier .d-nav-settings>summary::-webkit-details-marker{display:none}
.dossier .d-nav-settings .d-nav-chevron{width:14px;height:14px}
.dossier .d-nav-settings[open] .d-nav-chevron{transform:rotate(180deg)}
.dossier .d-nav-menu{position:absolute;z-index:4;top:calc(100% + 6px);right:0;min-width:205px;padding:5px;border:1px solid var(--d-line);border-radius:7px;background:#fff;box-shadow:0 5px 14px #17253a20}
.dossier .d-nav-menu a{display:block;padding:10px;border-radius:4px;text-decoration:none}
.dossier .d-nav-menu a:hover,.dossier .d-nav-menu [aria-current]{background:#e7f0fc;color:#144b8a}
.dossier .d-header{margin-block:12px}.dossier .d-tabs{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid var(--d-line);margin-bottom:12px}.dossier .d-tabs a{padding:9px 16px;text-decoration:none;color:#34475f;border-bottom:3px solid transparent}.dossier .d-tabs [aria-current]{border-color:var(--d-blue);color:var(--d-blue);font-weight:700}
.dossier .box,.dossier .d-panel{background:var(--d-panel);border:1px solid var(--d-line);padding:12px;margin:12px 0;border-radius:7px}.dossier .d-table{background:white;border:1px solid var(--d-line);border-radius:6px;margin:10px 0}.dossier table{width:100%;border-collapse:collapse}.dossier td,.dossier th{border-bottom:1px solid var(--d-line);text-align:left;padding:8px 11px;vertical-align:top}.dossier th{background:#e8eef6;font-weight:650;white-space:nowrap}.dossier tbody tr:nth-child(even){background:#f7f9fc}.dossier tbody tr:last-child td{border-bottom:none}.dossier .scroll{max-width:100%;overflow:auto}
.dossier input:not([type=checkbox]),.dossier select,.dossier textarea{border:1px solid #9aaec5;border-radius:5px;padding:6px 8px;max-width:100%;background:#fff;color:#17253a}.dossier label{display:inline-block;margin:4px 6px 4px 0}.dossier button,.dossier .d-button{display:inline-block;background:var(--d-blue);color:#fff;padding:6px 12px;border:1px solid var(--d-blue);border-radius:5px;font-weight:650;text-decoration:none;cursor:pointer}.dossier button:disabled{opacity:.55;cursor:default}.dossier .secondary,.dossier .d-button.secondary{background:#fff;color:var(--d-blue)}.dossier .d-plain{padding:0;border:0;background:none;color:var(--d-blue);text-decoration:underline}.dossier form{margin:0}.dossier .d-upload-menu{position:relative;margin:0}.dossier .d-upload-menu form{position:absolute;right:0;width:min(330px,80vw);z-index:4;box-shadow:0 8px 20px #17253a20}
.dossier .badge{padding:3px 7px;border-radius:4px;display:inline-block;background:#e8eef6;color:#25364d}.dossier .expired,.dossier .missing,.dossier .error{background:#feecee;color:#991b1b}.dossier .review,.dossier .expiring,.dossier .future,.dossier .d-alert{background:#fff3db;color:#754009}.dossier .valid,.dossier .permanent{background:#e5f5ec;color:#166534}.dossier .d-danger{color:#a41d28}.dossier .d-warning{color:#854700}.dossier .d-alert,.dossier .error{border-radius:5px;padding:8px 12px;margin:10px 0}
.dossier small{display:block;color:var(--d-muted);line-height:1.45}.dossier details{margin:8px 0}.dossier summary{cursor:pointer}.dossier .d-driver summary{background:#edf2f8;border-radius:5px;padding:8px 10px;font-weight:650}.dossier .d-driver .d-actions{padding:6px 0}.dossier .fields{display:flex;flex-wrap:wrap;gap:8px}.dossier .pages{display:flex;flex-wrap:wrap;gap:10px}.dossier .page{width:156px;border:1px solid var(--d-line);padding:4px}.dossier .page img{width:146px;height:178px;object-fit:contain}
.dossier .d-review-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,3fr);gap:16px;align-items:start}.dossier .d-scan{position:sticky;top:10px}.dossier .d-scan iframe{width:100%;height:65vh;border:1px solid var(--d-line);border-radius:6px}.dossier [hidden]{display:none!important}
@media(max-width:820px){.dossier .d-review-grid{grid-template-columns:1fr}.dossier .d-scan{position:static}.dossier .d-scan iframe{height:46vh}.dossier td,.dossier th{padding:7px 8px}}
@media(max-width:560px){.dossier .d-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.dossier .d-nav-settings{margin:0}.dossier .d-nav-settings>summary{display:flex}.dossier .d-nav-action{min-height:44px;padding:6px 5px;gap:5px}}
</style>
@include('dossiers.interactions')
@endonce
@php
    $dossierList = request()->routeIs('dossiers.index') && request()->query('tab') !== 'batches';
    $batchList = request()->routeIs('dossiers.index') && request()->query('tab') === 'batches';
    $dossierSection = $dossierList || request()->routeIs('dossiers.machine*', 'dossiers.driver*', 'dossiers.export*', 'dossiers.versions.*');
    $batchSection = $batchList || request()->routeIs('dossiers.batch', 'dossiers.source');
@endphp
<nav class="d-nav" aria-label="Điều hướng hồ sơ">
    <a @class(['d-nav-action', 'is-current'=>$dossierSection]) href="{{ route('dossiers.index') }}" @if($dossierList) aria-current="page" @elseif($dossierSection) aria-current="location" @endif>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 10h18"/></svg>
        <span>Hồ sơ điện tử</span>
    </a>
    <a @class(['d-nav-action', 'is-current'=>$batchSection]) href="{{ route('dossiers.index',['tab'=>'batches']) }}" @if($batchList) aria-current="page" @elseif($batchSection) aria-current="location" @endif>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l4 4v14H7zM14 3v5h5M10 12h5m-5 4h5"/><path d="M4 6v13"/></svg>
        <span>Đợt nhập PDF</span>
    </a>
    <a class="d-nav-action" href="{{ route('dossiers.excel') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v11m-4-4 4 4 4-4M4 15v5h16v-5"/></svg>
        <span>Xuất Excel</span>
    </a>
    <details class="d-nav-settings">
        <summary @class(['d-nav-action', 'is-current'=>request()->routeIs('dossiers.types', 'dossiers.ai.rules')])>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h4m4 0h8M4 12h9m4 0h3M4 18h3m4 0h9"/><circle cx="10" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="9" cy="18" r="2"/></svg>
            <span>Cài đặt</span>
            <svg class="d-nav-chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg>
        </summary>
        <div class="d-nav-menu">
            <a href="{{ route('dossiers.types') }}" @if(request()->routeIs('dossiers.types')) aria-current="page" @endif>Danh mục giấy / mốc hạn</a>
            <a href="{{ route('dossiers.ai.rules') }}" @if(request()->routeIs('dossiers.ai.rules')) aria-current="page" @endif>Quy tắc OCR</a>
        </div>
    </details>
</nav>
@if(session('success'))<p role="status" class="box">{{ session('success') }}</p>@endif
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(\Illuminate\Support\Facades\Schema::hasTable('dossier_workers'))
@php($dossierWorker=\App\Models\DossierWorker::latest('last_seen_at')->first())
<details><summary>Worker hồ sơ · {{ $dossierWorker && $dossierWorker->last_seen_at->gt(now()->subSeconds(120)) ? 'Đang kết nối' : 'Đang chờ worker / offline' }}</summary>
<small>Lần cập nhật {{ $dossierWorker?->last_seen_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') ?? 'chưa có' }}</small>
@if(!config('dossiers.ai.enabled') || !$dossierWorker?->ai_ready)<small>Chưa cấu hình AI hoặc AI đang tắt. Vẫn có thể phân loại thủ công.</small>@endif
</details>
@endif
