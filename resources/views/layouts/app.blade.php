<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'MMTB') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="app-shell" id="appShell">
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-brand">
            <div class="brand-mark">M</div>
            <div class="brand-copy">
                <strong>{{ config('app.name', 'MMTB') }}</strong>
                <span>Quản lý máy & thiết bị</span>
            </div>
            <button class="sidebar-collapse-button" id="sidebarCollapse" type="button" aria-label="Thu gọn menu" title="Thu gọn menu">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 7-5 5 5 5M20 4v16"/></svg>
            </button>
            <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Đóng menu">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="sidebar-section-label">Điều hướng</div>

        <nav class="sidebar-nav" aria-label="Điều hướng chính">
            <details class="sidebar-group" data-sidebar-group="overview" @if(request()->routeIs('dashboard', 'operation-center.*')) open @endif>
                <summary title="Tổng quan"><span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg></span><span class="sidebar-group-title">Tổng quan</span><span class="sidebar-chevron">⌄</span></summary>
                <div class="sidebar-group-links">
                    <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span>Dashboard</span></a>
                    <a class="sidebar-link {{ request()->routeIs('operation-center.*') ? 'active' : '' }}" href="{{ route('operation-center.index') }}"><span>Trung tâm vận hành</span></a>
                </div>
            </details>

            <details class="sidebar-group" data-sidebar-group="ocr" @if(request()->routeIs('ocr-monitoring.*', 'ocr-reviews.*', 'daily-images.*', 'machine-intakes.*')) open @endif>
                <summary title="Ảnh và OCR"><span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Zm4 4h8M8 12h5m-5 4h8M3 9h2m14 0h2M3 15h2m14 0h2"/></svg></span><span class="sidebar-group-title">Ảnh & OCR</span><span class="sidebar-chevron">⌄</span></summary>
                <div class="sidebar-group-links">
                    <a class="sidebar-link {{ request()->routeIs('ocr-monitoring.*') ? 'active' : '' }}" href="{{ route('ocr-monitoring.index') }}"><span>Giám sát OCR</span><span class="sidebar-live-dot" title="Realtime"></span></a>
                    <a class="sidebar-link {{ request()->routeIs('ocr-reviews.*') ? 'active' : '' }}" href="{{ route('ocr-reviews.index') }}"><span>Hậu kiểm OCR</span></a>
                    <a class="sidebar-link {{ request()->routeIs('daily-images.*') ? 'active' : '' }}" href="{{ route('daily-images.index') }}"><span>Kho ảnh hằng ngày</span></a>
                    <a class="sidebar-link {{ request()->routeIs('machine-intakes.*') ? 'active' : '' }}" href="{{ route('machine-intakes.index') }}"><span>AI tiếp nhận máy</span></a>
                </div>
            </details>

            <details class="sidebar-group" data-sidebar-group="automation" @if(request()->routeIs('automation-health.*', 'zalo-accounts.*', 'ai-reconciliation.*')) open @endif>
                <summary title="Tự động hóa và AI"><span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M4 18h3v-5H4v5Zm6 0h4V6h-4v12Zm7 0h3V9h-3v9ZM3 21h18M5 10l5-5 4 3 5-5"/></svg></span><span class="sidebar-group-title">Tự động hóa & AI</span><span class="sidebar-chevron">⌄</span></summary>
                <div class="sidebar-group-links">
                    <a class="sidebar-link {{ request()->routeIs('automation-health.*') ? 'active' : '' }}" href="{{ route('automation-health.index') }}"><span>Giám sát tự động</span></a>
                    <a class="sidebar-link {{ request()->routeIs('zalo-accounts.*') ? 'active' : '' }}" href="{{ route('zalo-accounts.index') }}"><span>Tài khoản Zalo</span></a>
                    <a class="sidebar-link {{ request()->routeIs('ai-reconciliation.*') ? 'active' : '' }}" href="{{ route('ai-reconciliation.index') }}"><span>Đối soát AI</span></a>
                </div>
            </details>

            <details class="sidebar-group" data-sidebar-group="equipment" @if(request()->routeIs('companies.*', 'machines.*', 'reconciliation-*', 'projects.*', 'command-centers.*', 'drivers.*', 'expiries.*')) open @endif>
                <summary title="Quản lý MMTB"><span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16v10H4V7Zm3-3h10v3H7V4Zm1 13v3m8-3v3M8 11h8"/></svg></span><span class="sidebar-group-title">Quản lý MMTB</span><span class="sidebar-chevron">⌄</span></summary>
                <div class="sidebar-group-links">
                    <a class="sidebar-link {{ request()->routeIs('companies.*') ? 'active' : '' }}" href="{{ route('companies.index') }}"><span>Công ty</span></a>
                    <a class="sidebar-link {{ request()->routeIs('machines.*') ? 'active' : '' }}" href="{{ route('machines.index') }}"><span>Thiết bị</span></a>
                    <a class="sidebar-link {{ request()->routeIs('reconciliation-*') ? 'active' : '' }}" href="{{ route('reconciliation-periods.index') }}"><span>Đối chiếu</span></a>
                    <a class="sidebar-link {{ request()->routeIs('projects.*') ? 'active' : '' }}" href="{{ route('projects.index') }}"><span>Dự án</span></a>
                    <a class="sidebar-link {{ request()->routeIs('command-centers.*') ? 'active' : '' }}" href="{{ route('command-centers.index') }}"><span>Ban chỉ huy</span></a>
                    <a class="sidebar-link {{ request()->routeIs('drivers.*') ? 'active' : '' }}" href="{{ route('drivers.index') }}"><span>Tài xế</span></a>
                    <a class="sidebar-link {{ request()->routeIs('expiries.*') ? 'active' : '' }}" href="{{ route('expiries.index') }}"><span>Hồ sơ</span></a>
                </div>
            </details>

            <details class="sidebar-group" data-sidebar-group="system" @if(request()->routeIs('activities.*', 'notifications.*')) open @endif>
                <summary title="Hệ thống"><span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M12 8v5l3 2M4.93 4.93A10 10 0 1 1 2 12h3m-3-4v4h4"/></svg></span><span class="sidebar-group-title">Hệ thống</span><span class="sidebar-chevron">⌄</span></summary>
                <div class="sidebar-group-links">
                    <a class="sidebar-link {{ request()->routeIs('activities.*') ? 'active' : '' }}" href="{{ route('activities.index') }}"><span>Nhật ký hoạt động</span></a>
                    <a class="sidebar-link {{ request()->routeIs('notifications.*') ? 'active' : '' }}" href="{{ route('notifications.index') }}"><span>Thông báo</span>
                        @auth
                            @php($sidebarNotificationCount = auth()->user()->unreadNotifications()->count())
                            @if ($sidebarNotificationCount > 0)<span class="sidebar-count">{{ $sidebarNotificationCount > 99 ? '99+' : $sidebarNotificationCount }}</span>@endif
                        @endauth
                    </a>
                </div>
            </details>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name ?? 'U', 0, 1)) }}</div>
                <div class="user-copy">
                    <strong>{{ Auth::user()->name ?? 'Người dùng' }}</strong>
                    <span>{{ Auth::user()->email ?? '' }}</span>
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="logout-button">
                    <svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3m10-8h6v16h-6"/></svg>
                    Đăng xuất
                </button>
            </form>
        </div>
    </aside>

    <section class="app-content-wrap">
        <header class="app-topbar">
            <button class="mobile-menu-button" id="sidebarOpen" type="button" aria-label="Mở menu">
                <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>

            <div class="global-search" id="globalSearch">
                <form action="{{ route('global-search.index') }}" method="GET" class="global-search-form" autocomplete="off">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
                    <input id="globalSearchInput" name="q" type="search"
                           value="{{ request()->routeIs('global-search.*') ? request('q') : '' }}"
                           placeholder="Tìm mã máy, số khung, biển số, tài xế...">
                    <span class="global-search-shortcut">Ctrl K</span>
                </form>
                <div class="global-search-dropdown" id="globalSearchDropdown" hidden></div>
            </div>

            <div class="topbar-copy">
                <span>MMTB TOÀN THẮNG</span>
                <time id="vietnamClock" datetime="{{ now('Asia/Ho_Chi_Minh')->toIso8601String() }}">
                    GMT+7 · {{ now('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}
                </time>
            </div>
            <div>
                @auth
                    <a href="{{ route('notifications.index') }}"
                    class="relative inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                    title="Thông báo">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M14.857 17.082A23.848 23.848 0 0 1 12 17.25c-.97 0-1.923-.058-2.857-.168m5.714 0a3 3 0 1 1-5.714 0m5.714 0c1.306-.151 2.563-.422 3.75-.795A8.966 8.966 0 0 1 18 12c0-3.314-2.686-6-6-6s-6 2.686-6 6a8.966 8.966 0 0 1-.607 4.287c1.188.373 2.445.644 3.75.795"/>
                        </svg>

                        @php($notificationCount = auth()->user()->unreadNotifications()->count())

                        @if ($notificationCount > 0)
                            <span class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                                {{ $notificationCount > 99 ? '99+' : $notificationCount }}
                            </span>
                        @endif
                    </a>
                @endauth
            </div>
        </header>

        <main class="app-main">
            @yield('content')
        </main>
    </section>
</div>

<style>
:root{--sidebar-width:248px}.app-sidebar{padding:14px 12px}.sidebar-brand{min-height:56px;padding:4px 7px 12px}.brand-mark{width:38px;height:38px}.sidebar-section-label{padding:14px 10px 6px}.sidebar-nav{gap:2px}.sidebar-link{min-height:38px;gap:9px;padding:7px 10px;border-radius:9px}.sidebar-icon{width:20px;height:20px}.sidebar-footer{padding-top:11px}.sidebar-user{padding:6px 7px 10px}.logout-button{min-height:36px}.app-topbar{gap:18px}.topbar-copy time{color:var(--text);font-size:12px;font-weight:750;white-space:nowrap}.global-search{position:relative;width:min(620px,55vw);margin-right:auto}.global-search-form{display:flex;align-items:center;gap:10px;height:42px;padding:0 12px;border:1px solid #dbe3ee;border-radius:12px;background:#f8fafc}.global-search-form:focus-within{border-color:#8bb2ff;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.1)}.global-search-form svg{width:19px;height:19px;fill:none;stroke:#64748b;stroke-width:2}.global-search-form input{min-width:0;flex:1;border:0;outline:0;background:transparent;color:#0f172a;font-size:13px}.global-search-shortcut{padding:3px 7px;border:1px solid #dbe3ee;border-radius:6px;background:#fff;color:#64748b;font-size:10px;font-weight:700;white-space:nowrap}.global-search-dropdown{position:absolute;top:49px;right:0;left:0;z-index:1000;max-height:min(70vh,620px);overflow:auto;border:1px solid #dbe3ee;border-radius:15px;background:#fff;box-shadow:0 20px 50px rgba(15,23,42,.18)}.global-search-loading,.global-search-message{padding:22px;color:#64748b;font-size:13px;text-align:center}.global-search-group{padding:8px}.global-search-group+.global-search-group{border-top:1px solid #eef2f7}.global-search-group-title{padding:7px 9px;color:#64748b;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.global-search-item{display:grid;grid-template-columns:36px minmax(0,1fr) 18px;align-items:center;gap:10px;padding:9px;border-radius:10px;color:#0f172a;text-decoration:none}.global-search-item:hover,.global-search-item.is-active{background:#f1f5f9;color:#0f172a}.global-search-item-mark{display:inline-flex;width:34px;height:34px;align-items:center;justify-content:center;border-radius:9px;background:#e8efff;color:#2558c7;font-size:12px;font-weight:800}.global-search-item-copy{display:flex;min-width:0;flex-direction:column}.global-search-item-copy strong{overflow:hidden;font-size:13px;text-overflow:ellipsis;white-space:nowrap}.global-search-item-copy small{margin-top:2px;overflow:hidden;color:#64748b;font-size:11px;text-overflow:ellipsis;white-space:nowrap}.global-search-item-arrow{color:#94a3b8}.global-search-all{display:block;padding:12px;border-top:1px solid #eef2f7;color:#2563eb;font-size:12px;font-weight:800;text-align:center;text-decoration:none}@media(max-width:991.98px){.global-search{width:auto;flex:1}.global-search-shortcut{display:none}.topbar-copy span{display:none}}@media(max-width:575.98px){.app-topbar{padding-right:10px;padding-left:10px}.global-search-form{height:39px}.global-search-form input{font-size:12px}.topbar-copy{display:none}}
.sidebar-collapse-button{display:grid;width:31px;height:31px;place-items:center;margin-left:auto;border:0;border-radius:8px;background:#f1f5f9;color:#64748b;transition:.18s}.sidebar-collapse-button:hover{color:#2563eb;background:#e8efff}.sidebar-collapse-button svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.sidebar-nav{min-height:0;overflow-y:auto;scrollbar-width:thin}.sidebar-group{border-radius:10px}.sidebar-group summary{display:flex;min-height:40px;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;color:#475467;font-weight:800;cursor:pointer;list-style:none;transition:.18s}.sidebar-group summary::-webkit-details-marker{display:none}.sidebar-group summary:hover,.sidebar-group[open]>summary{color:#1d4ed8;background:#f6f9ff}.sidebar-group-title{white-space:nowrap}.sidebar-chevron{margin-left:auto;color:#94a3b8;font-size:15px;transition:transform .18s}.sidebar-group[open]>summary .sidebar-chevron{transform:rotate(180deg)}.sidebar-group-links{padding:2px 0 5px}.sidebar-group-links .sidebar-link{min-height:34px;margin:1px 0;padding:6px 10px 6px 40px;font-size:12.5px}.sidebar-count{min-width:21px;margin-left:auto;padding:2px 6px;border-radius:999px;background:#e11d48;color:#fff;font-size:10px;text-align:center}.sidebar-live-dot{width:7px;height:7px;margin-left:auto;border-radius:50%;background:#10b981;box-shadow:0 0 0 3px #d1fae5}.sidebar-collapsed{--sidebar-width:72px}.sidebar-collapsed .app-sidebar{transition:width .18s ease}.sidebar-collapsed .app-sidebar:hover{width:248px;box-shadow:12px 0 35px rgba(15,23,42,.12)}.sidebar-collapsed .app-sidebar:not(:hover) .brand-copy,.sidebar-collapsed .app-sidebar:not(:hover) .sidebar-collapse-button,.sidebar-collapsed .app-sidebar:not(:hover) .sidebar-section-label,.sidebar-collapsed .app-sidebar:not(:hover) .sidebar-group-title,.sidebar-collapsed .app-sidebar:not(:hover) .sidebar-chevron,.sidebar-collapsed .app-sidebar:not(:hover) .sidebar-group-links,.sidebar-collapsed .app-sidebar:not(:hover) .sidebar-footer{display:none}.sidebar-collapsed .app-sidebar:not(:hover) .sidebar-brand{justify-content:center;padding-right:0;padding-left:0}.sidebar-collapsed .app-sidebar:not(:hover) .sidebar-group summary{justify-content:center;padding-right:0;padding-left:0}.sidebar-collapsed .sidebar-collapse-button svg{transform:rotate(180deg)}
@media(min-width:992px){.sidebar-group:not([open]):hover>.sidebar-group-links{display:block}}
@media(max-width:991.98px){.sidebar-collapsed{--sidebar-width:248px}.sidebar-collapse-button{display:none}.sidebar-nav{overflow-y:auto}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const shell = document.getElementById('appShell');
    const openButton = document.getElementById('sidebarOpen');
    const closeButton = document.getElementById('sidebarClose');
    const collapseButton = document.getElementById('sidebarCollapse');
    const backdrop = document.getElementById('sidebarBackdrop');
    const search = document.getElementById('globalSearch');
    const input = document.getElementById('globalSearchInput');
    const dropdown = document.getElementById('globalSearchDropdown');
    const vietnamClock = document.getElementById('vietnamClock');

    const updateVietnamClock = () => {
        if (!vietnamClock) return;
        const parts = Object.fromEntries(new Intl.DateTimeFormat('vi-VN', {
            timeZone: 'Asia/Ho_Chi_Minh', hour: '2-digit', minute: '2-digit',
            day: '2-digit', month: '2-digit', year: 'numeric', hourCycle: 'h23',
        }).formatToParts(new Date()).map(({type, value}) => [type, value]));
        vietnamClock.textContent = `GMT+7 · ${parts.hour}:${parts.minute} · ${parts.day}/${parts.month}/${parts.year}`;
        vietnamClock.dateTime = new Date().toISOString();
    };
    updateVietnamClock();
    setInterval(updateVietnamClock, 30000);

    const openSidebar = () => shell?.classList.add('sidebar-open');
    const closeSidebar = () => shell?.classList.remove('sidebar-open');

    openButton?.addEventListener('click', openSidebar);
    closeButton?.addEventListener('click', closeSidebar);
    backdrop?.addEventListener('click', closeSidebar);

    try {
        if (localStorage.getItem('mmtb-sidebar-collapsed') === '1' && window.innerWidth >= 992) shell?.classList.add('sidebar-collapsed');
    } catch (_) {}
    collapseButton?.addEventListener('click', () => {
        const collapsed = shell?.classList.toggle('sidebar-collapsed');
        collapseButton.setAttribute('aria-label', collapsed ? 'Mở rộng menu' : 'Thu gọn menu');
        collapseButton.title = collapsed ? 'Mở rộng menu' : 'Thu gọn menu';
        try { localStorage.setItem('mmtb-sidebar-collapsed', collapsed ? '1' : '0'); } catch (_) {}
    });

    document.querySelectorAll('[data-sidebar-group]').forEach(group => {
        const key = `mmtb-sidebar-group-${group.dataset.sidebarGroup}`;
        const containsActivePage = Boolean(group.querySelector('.sidebar-link.active'));
        try {
            if (!containsActivePage && localStorage.getItem(key) !== null) group.open = localStorage.getItem(key) === '1';
        } catch (_) {}
        group.addEventListener('toggle', () => {
            if (containsActivePage && !group.open) { group.open = true; return; }
            try { localStorage.setItem(key, group.open ? '1' : '0'); } catch (_) {}
        });
    });

    let timer = null;
    let controller = null;
    let activeIndex = -1;

    const labels = {
        machines: ['Thiết bị', 'M'],
        drivers: ['Tài xế', 'T'],
        projects: ['Dự án', 'D'],
        command_centers: ['Ban chỉ huy', 'B'],
    };

    const escapeHtml = (value = '') => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const closeDropdown = () => {
        dropdown.hidden = true;
        dropdown.innerHTML = '';
        activeIndex = -1;
    };

    const render = (data) => {
        const groups = Object.entries(data.groups || {})
            .filter(([, items]) => items.length > 0)
            .map(([key, items]) => {
                const [label, mark] = labels[key];
                const links = items.map(item => `
                    <a class="global-search-item" href="${escapeHtml(item.url)}">
                        <span class="global-search-item-mark">${mark}</span>
                        <span class="global-search-item-copy">
                            <strong>${escapeHtml(item.title)}</strong>
                            <small>${escapeHtml(item.subtitle || item.meta || '')}</small>
                        </span>
                        <span class="global-search-item-arrow">→</span>
                    </a>
                `).join('');

                return `
                    <div class="global-search-group">
                        <div class="global-search-group-title">${label}</div>
                        ${links}
                    </div>
                `;
            }).join('');

        if (!groups) {
            dropdown.innerHTML = '<div class="global-search-message">Không tìm thấy kết quả phù hợp.</div>';
        } else {
            dropdown.innerHTML = groups + `
                <a class="global-search-all" href="${escapeHtml(data.all_url)}">
                    Xem toàn bộ ${data.total} kết quả
                </a>
            `;
        }

        dropdown.hidden = false;
        activeIndex = -1;
    };

    const runSearch = async () => {
        const query = input.value.trim();

        if (query.length < 2) {
            closeDropdown();
            return;
        }

        controller?.abort();
        controller = new AbortController();
        dropdown.innerHTML = '<div class="global-search-loading">Đang tìm kiếm...</div>';
        dropdown.hidden = false;

        try {
            const response = await fetch(`{{ route('global-search.quick') }}?q=${encodeURIComponent(query)}`, {
                headers: {'Accept': 'application/json'},
                signal: controller.signal,
            });

            if (!response.ok) throw new Error('Search failed');
            render(await response.json());
        } catch (error) {
            if (error.name !== 'AbortError') {
                dropdown.innerHTML = '<div class="global-search-message">Không thể tìm kiếm. Vui lòng thử lại.</div>';
            }
        }
    };

    input?.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(runSearch, 300);
    });

    input?.addEventListener('focus', function () {
        if (input.value.trim().length >= 2) runSearch();
    });

    input?.addEventListener('keydown', function (event) {
        const items = [...dropdown.querySelectorAll('.global-search-item')];

        if (event.key === 'ArrowDown' && items.length) {
            event.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
        } else if (event.key === 'ArrowUp' && items.length) {
            event.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
        } else if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
            event.preventDefault();
            window.location.href = items[activeIndex].href;
            return;
        } else if (event.key === 'Escape') {
            closeDropdown();
            input.blur();
            return;
        } else {
            return;
        }

        items.forEach((item, index) => item.classList.toggle('is-active', index === activeIndex));
        items[activeIndex]?.scrollIntoView({block: 'nearest'});
    });

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            input?.focus();
            input?.select();
        }
    });

    document.addEventListener('click', function (event) {
        if (!search?.contains(event.target)) closeDropdown();
    });
});
</script>
</body>
</html>
