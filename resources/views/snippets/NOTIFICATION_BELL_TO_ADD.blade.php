{{-- Dán vào thanh header/topbar, tại vị trí muốn hiển thị chuông thông báo. --}}
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
