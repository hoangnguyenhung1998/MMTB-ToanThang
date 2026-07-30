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
            <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Đóng menu">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="sidebar-section-label">Điều hướng</div>

        <nav class="sidebar-nav">
            <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                <span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg></span>
                <span>Dashboard</span>
            </a>

            <a class="sidebar-link {{ request()->routeIs('operation-center.*') ? 'active' : '' }}" href="{{ route('operation-center.index') }}">
                <span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4V5Zm4 4h8M8 13h5M7 9h.01M7 13h.01M7 17h.01M8 17h8"/></svg></span>
                <span>Trung tâm vận hành</span>
            </a>

            <a class="sidebar-link {{ request()->routeIs('machines.*') ? 'active' : '' }}" href="{{ route('machines.index') }}">
                <span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16v10H4V7Zm3-3h10v3H7V4Zm1 13v3m8-3v3M8 11h8"/></svg></span>
                <span>Thiết bị</span>
            </a>
            <a class="sidebar-link {{ request()->routeIs('projects.*') ? 'active' : '' }}" href="{{ route('projects.index') }}">
                <span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M4 20V8l8-4 8 4v12H4Zm4-8h8m-8 4h8"/></svg></span>
                <span>Dự án</span>
            </a>
            <a class="sidebar-link {{ request()->routeIs('command-centers.*') ? 'active' : '' }}" href="{{ route('command-centers.index') }}">
                <span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M3 20h18M5 20V9l7-5 7 5v11M9 20v-6h6v6"/></svg></span>
                <span>Ban chỉ huy</span>
            </a>
            <a class="sidebar-link {{ request()->routeIs('drivers.*') ? 'active' : '' }}" href="{{ route('drivers.index') }}">
                <span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 8a7 7 0 0 0-14 0"/></svg></span>
                <span>Tài xế</span>
            </a>
            <a class="sidebar-link {{ request()->routeIs('expiries.*') ? 'active' : '' }}" href="{{ route('expiries.index') }}">
                <span class="sidebar-icon"><svg viewBox="0 0 24 24"><path d="M7 3h10l3 3v15H4V3h3Zm0 5h10M7 12h10M7 16h6"/></svg></span>
                <span>Hồ sơ</span>
            </a>
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
            <div class="topbar-copy">
                <span>MMTB TOÀN THẮNG</span>
                <strong>{{ now()->format('d/m/Y') }}</strong>
            </div>
        </header>

        <main class="app-main">
            @yield('content')
        </main>
    </section>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const shell = document.getElementById('appShell');
        const openButton = document.getElementById('sidebarOpen');
        const closeButton = document.getElementById('sidebarClose');
        const backdrop = document.getElementById('sidebarBackdrop');

        const openSidebar = () => shell?.classList.add('sidebar-open');
        const closeSidebar = () => shell?.classList.remove('sidebar-open');

        openButton?.addEventListener('click', openSidebar);
        closeButton?.addEventListener('click', closeSidebar);
        backdrop?.addEventListener('click', closeSidebar);
    });
</script>
</body>
</html>
