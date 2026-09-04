@extends('layouts.app')

@section('content')
@php
    $activeId = data_get($collector?->metrics, 'active_account_id');
    $statusLabels = ['HEALTHY' => 'Đang hoạt động', 'DEGRADED' => 'Chập chờn', 'OFFLINE' => 'Mất kết nối', 'PAUSED' => 'Tạm dừng'];
    $commandLabels = ['ZALO_ACCOUNT_SWITCH' => 'Chuyển tài khoản', 'ZALO_GROUPS_UPDATE' => 'Lưu cài đặt nhóm'];
    $commandStatusLabels = ['PENDING' => 'Đang chờ', 'PROCESSING' => 'Đang xử lý', 'COMPLETED' => 'Hoàn thành', 'FAILED' => 'Thất bại'];
@endphp
<style>
    .za-wrap{max-width:1200px;margin:0 auto;padding:28px 24px 48px}.za-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:20px}.za-title{font-size:28px;font-weight:800;color:#0f172a;margin:0 0 5px}.za-muted{color:#64748b}.za-status{padding:9px 13px;border-radius:999px;font-size:13px;font-weight:750;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.za-alert{padding:12px 15px;border-radius:10px;margin-bottom:16px}.za-alert-danger{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.za-alert-success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.za-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.za-card{background:#fff;border:1px solid #dbe4f0;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.04)}.za-card.active{border-color:#60a5fa;box-shadow:0 8px 28px rgba(37,99,235,.10)}.za-card-head{padding:18px;border-bottom:1px solid #edf1f6;display:flex;justify-content:space-between;gap:12px}.za-name{font-size:18px;font-weight:800;color:#0f172a}.za-id{font:12px ui-monospace,SFMono-Regular,Menlo,monospace;color:#64748b;margin-top:3px}.za-badge{display:inline-flex;align-items:center;height:25px;padding:0 9px;border-radius:999px;background:#e0f2fe;color:#0369a1;font-size:11px;font-weight:800}.za-badge.idle{background:#f1f5f9;color:#64748b}.za-meta{display:flex;gap:16px;padding:13px 18px;background:#f8fafc;color:#475569;font-size:13px}.za-meta strong{color:#0f172a}.za-body{padding:18px}.za-groups{display:grid;gap:8px;max-height:340px;overflow:auto;padding-right:4px;margin:13px 0 16px}.za-group{display:flex;align-items:flex-start;gap:10px;padding:10px 11px;border:1px solid #e2e8f0;border-radius:10px;cursor:pointer}.za-group:hover{background:#f8fafc}.za-group input{margin-top:3px;width:17px;height:17px}.za-group-name{display:block;color:#1e293b;font-weight:650;font-size:13px}.za-group-id{display:block;color:#94a3b8;font:11px ui-monospace,SFMono-Regular,Menlo,monospace;margin-top:2px}.za-actions{display:flex;gap:9px;flex-wrap:wrap}.za-btn{border:0;border-radius:9px;padding:9px 13px;font-weight:750;font-size:13px;cursor:pointer}.za-btn-primary{background:#2563eb;color:#fff}.za-btn-secondary{background:#0f172a;color:#fff}.za-btn:disabled{background:#cbd5e1;color:#64748b;cursor:not-allowed}.za-history{margin-top:20px;background:#fff;border:1px solid #dbe4f0;border-radius:16px;overflow:hidden}.za-history h2{font-size:17px;margin:0;padding:16px 18px;border-bottom:1px solid #e5eaf2}.za-table{width:100%;border-collapse:collapse;font-size:13px}.za-table th,.za-table td{padding:11px 14px;border-top:1px solid #edf1f6;text-align:left}.za-table th{background:#f8fafc;color:#64748b;font-size:11px}.za-overlay{position:fixed;inset:0;background:rgba(15,23,42,.50);display:none;align-items:center;justify-content:center;z-index:9999;padding:20px}.za-overlay.show{display:flex}.za-modal{width:min(430px,100%);background:#fff;border-radius:18px;padding:25px;text-align:center;box-shadow:0 24px 70px rgba(15,23,42,.3)}.za-spinner{width:48px;height:48px;border:4px solid #dbeafe;border-top-color:#2563eb;border-radius:50%;animation:za-spin .8s linear infinite;margin:0 auto 17px}.za-progress{height:6px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:17px}.za-progress span{display:block;width:35%;height:100%;background:#2563eb;border-radius:999px;animation:za-progress 1.5s ease-in-out infinite}.za-toast{position:fixed;right:22px;bottom:22px;z-index:10000;max-width:390px;padding:13px 16px;border-radius:11px;color:#fff;font-weight:700;box-shadow:0 16px 40px rgba(15,23,42,.25);display:none}.za-toast.show{display:block}.za-toast.success{background:#059669}.za-toast.error{background:#dc2626}@keyframes za-spin{to{transform:rotate(360deg)}}@keyframes za-progress{0%{transform:translateX(-100%)}100%{transform:translateX(300%)}}@media(max-width:800px){.za-grid{grid-template-columns:1fr}.za-head{display:block}.za-status{display:inline-flex;margin-top:12px}.za-wrap{padding:20px 14px 40px}.za-history{overflow-x:auto}}
</style>

<div class="za-wrap">
    <div class="za-head">
        <div><h1 class="za-title">Tài khoản Zalo</h1><p class="za-muted" style="margin:0">Quản lý tài khoản và chọn chính xác nhóm được phép đưa ảnh vào hệ thống.</p></div>
        @if($collector)<span class="za-status">{{ $statusLabels[$collector->effective_status] ?? $collector->effective_status }}</span>@endif
    </div>

    @if(session('success'))<div class="za-alert za-alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="za-alert za-alert-danger">{{ $errors->first() }}</div>@endif
    @unless($collector)
        <div class="za-alert za-alert-danger">Chưa nhận được heartbeat của Zalo Collector. Hãy kiểm tra Health Agent trên laptop.</div>
    @else
        <div class="za-grid">
            @forelse($accounts as $account)
                @php
                    $isActive = ($account['id'] ?? null) === $activeId;
                    $groups = $account['groups'] ?? [];
                @endphp
                <article class="za-card {{ $isActive ? 'active' : '' }}">
                    <div class="za-card-head">
                        <div><div class="za-name">{{ $account['name'] }}</div><div class="za-id">{{ $account['id'] }}</div></div>
                        <span class="za-badge {{ $isActive ? '' : 'idle' }}">{{ $isActive ? 'ĐANG CHẠY' : 'DỰ PHÒNG' }}</span>
                    </div>
                    <div class="za-meta">
                        <span>Phiên: <strong>{{ ($account['has_session'] ?? false) ? 'Đã lưu' : 'Thiếu' }}</strong></span>
                        <span>Đang lấy ảnh: <strong>{{ $account['group_count'] ?? 0 }}/{{ $account['available_group_count'] ?? count($groups) }} nhóm</strong></span>
                    </div>
                    <div class="za-body">
                        <form class="js-zalo-command" method="POST" action="{{ route('zalo-accounts.commands.store') }}" data-label="Đang lưu cài đặt nhóm cho {{ $account['name'] }}…">
                            @csrf
                            <input type="hidden" name="action" value="ZALO_GROUPS_UPDATE">
                            <input type="hidden" name="account_id" value="{{ $account['id'] }}">
                            <strong style="font-size:14px;color:#334155">Nhóm được phép lấy ảnh</strong>
                            @if(count($groups))
                                <div class="za-groups">
                                    @foreach($groups as $group)
                                        <label class="za-group">
                                            <input type="checkbox" name="group_ids[]" value="{{ $group['id'] }}" @checked($group['enabled'] ?? false)>
                                            <span><span class="za-group-name">{{ $group['name'] }}</span><span class="za-group-id">{{ $group['id'] }}</span></span>
                                        </label>
                                    @endforeach
                                </div>
                            @else
                                <div class="za-alert" style="background:#fffbeb;color:#92400e;border:1px solid #fde68a;margin:13px 0">Chưa có danh mục nhóm. Danh sách sẽ tự cập nhật khi tài khoản này được Collector khởi động.</div>
                            @endif
                            <div class="za-actions">
                                <button class="za-btn za-btn-secondary" type="submit" @disabled(!count($groups))>Lưu cài đặt nhóm</button>
                            </div>
                        </form>
                        @unless($isActive)
                            <form class="js-zalo-command" method="POST" action="{{ route('zalo-accounts.commands.store') }}" data-label="Đang chuyển Collector sang {{ $account['name'] }}…" style="margin-top:9px">
                                @csrf
                                <input type="hidden" name="action" value="ZALO_ACCOUNT_SWITCH">
                                <input type="hidden" name="account_id" value="{{ $account['id'] }}">
                                <button class="za-btn za-btn-primary" type="submit" @disabled(!($account['ready'] ?? false))>Chuyển sang tài khoản này</button>
                            </form>
                        @endunless
                    </div>
                </article>
            @empty
                <div class="za-alert za-alert-danger">Collector chưa gửi danh sách tài khoản Zalo.</div>
            @endforelse
        </div>

        <section class="za-history">
            <h2>Lịch sử thao tác gần đây</h2>
            <table class="za-table"><thead><tr><th>THỜI GIAN</th><th>THAO TÁC</th><th>TÀI KHOẢN</th><th>TRẠNG THÁI</th><th>THÔNG TIN</th></tr></thead><tbody>
                @forelse($commands as $command)
                    <tr><td>{{ $command->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') }}</td><td>{{ $commandLabels[$command->action] ?? $command->action }}</td><td>{{ data_get($command->payload, 'account_id', '—') }}</td><td><strong>{{ $commandStatusLabels[$command->status] ?? $command->status }}</strong></td><td>{{ $command->error_message ?: data_get($command->result, 'message', '—') }}</td></tr>
                @empty<tr><td colspan="5" class="za-muted">Chưa có thao tác.</td></tr>@endforelse
            </tbody></table>
        </section>
    @endunless
</div>

<div class="za-overlay" id="zaloOverlay" role="dialog" aria-modal="true" aria-live="polite"><div class="za-modal"><div class="za-spinner"></div><h2 style="font-size:19px;color:#0f172a;margin:0 0 7px">Đang xử lý</h2><p id="zaloProgressText" class="za-muted" style="margin:0">Đang gửi lệnh tới laptop…</p><div class="za-progress"><span></span></div><small class="za-muted" style="display:block;margin-top:12px">Vui lòng giữ trang này mở. Thường mất 30–60 giây.</small></div></div>
<div class="za-toast" id="zaloToast" role="status"></div>

<script>
(() => {
    const overlay = document.getElementById('zaloOverlay');
    const progress = document.getElementById('zaloProgressText');
    const toast = document.getElementById('zaloToast');
    const showToast = (message, type) => { toast.textContent = message; toast.className = `za-toast show ${type}`; setTimeout(() => toast.classList.remove('show'), 6000); };
    const firstError = (data) => Object.values(data.errors || {}).flat()[0] || data.message || 'Không thể gửi lệnh.';
    const poll = async (url, startedAt) => {
        try {
            const response = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Không đọc được trạng thái lệnh.');
            progress.textContent = data.command.message;
            if (data.command.done) {
                overlay.classList.remove('show');
                showToast(data.command.message, data.command.successful ? 'success' : 'error');
                if (data.command.successful) setTimeout(() => window.location.reload(), 1200);
                return;
            }
            if (Date.now() - startedAt > 180000) throw new Error('Quá thời gian chờ xác nhận. Hãy kiểm tra Health Agent và lịch sử lệnh.');
            setTimeout(() => poll(url, startedAt), 2000);
        } catch (error) {
            overlay.classList.remove('show'); showToast(error.message, 'error');
        }
    };
    document.querySelectorAll('.js-zalo-command').forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (form.querySelector('[name="action"]').value === 'ZALO_GROUPS_UPDATE' && !form.querySelector('[name="group_ids[]"]:checked')) {
            showToast('Anh cần chọn ít nhất một nhóm để Collector lấy ảnh.', 'error'); return;
        }
        overlay.classList.add('show'); progress.textContent = form.dataset.label || 'Đang gửi lệnh tới laptop…';
        try {
            const response = await fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();
            if (!response.ok) throw new Error(firstError(data));
            progress.textContent = data.message; poll(data.status_url, Date.now());
        } catch (error) {
            overlay.classList.remove('show'); showToast(error.message, 'error');
        }
    }));
})();
</script>
@endsection
