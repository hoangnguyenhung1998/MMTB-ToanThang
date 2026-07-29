<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'MMTB') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="d-flex">
    <aside class="app-sidebar bg-white border-end p-3" style="width: 260px;">
        <div class="mb-4">
            <span class="fw-bold">{{ config('app.name', 'MMTB') }}</span>
        </div>
        <nav class="nav flex-column gap-1">
            <a class="nav-link" href="{{ route('dashboard') }}">DASHBOARD</a>
            <a class="nav-link" href="{{ route('machines.index') }}">THIẾT BỊ</a>
            <a class="nav-link" href="{{ route('projects.index') }}">DỰ ÁN</a>
            <a class="nav-link" href="{{ route('command-centers.index') }}">BAN CHỈ HUY</a>
            <a class="nav-link" href="{{ route('drivers.index') }}">TÀI XẾ</a>
            <a class="nav-link" href="{{ route('expiries.index') }}">HỒ SƠ</a>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn btn-outline-danger w-100">LOGOUT</button>
            </form>
        </nav>
    </aside>

    <main class="flex-grow-1 p-4">
        @yield('content')
    </main>
</div>
</body>
</html>
