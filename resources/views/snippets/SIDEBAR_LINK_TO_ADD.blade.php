{{-- Dán ngay sau link "Nhật ký hoạt động" hoặc "Trung tâm vận hành". --}}
<a class="sidebar-link {{ request()->routeIs('notifications.*') ? 'active' : '' }}"
   href="{{ route('notifications.index') }}">
    <span class="sidebar-icon">
        <svg viewBox="0 0 24 24">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>
        </svg>
    </span>

    <span>Thông báo</span>

    @auth
        @php($sidebarNotificationCount = auth()->user()->unreadNotifications()->count())

        @if ($sidebarNotificationCount > 0)
            <span class="ml-auto rounded-full bg-rose-600 px-2 py-0.5 text-xs font-bold text-white">
                {{ $sidebarNotificationCount > 99 ? '99+' : $sidebarNotificationCount }}
            </span>
        @endif
    @endauth
</a>
