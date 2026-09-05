@extends('layouts.app')

@section('content')
@php
    $capacityLevel = data_get($summary, 'capacity.level', 'healthy');
    $capacityLabels = ['healthy' => 'Đủ công suất', 'warning' => 'Sát ngưỡng', 'danger' => 'Không kịp ngày'];
    $stageLabels = ['CLASSIFY' => 'Phân loại', 'TIMEMARK' => 'TimeMark', 'JOURNAL' => 'Nhật trình'];
    $runStatusLabels = ['PROCESSING' => 'Đang chạy', 'COMPLETED' => 'Hoàn thành', 'FAILED' => 'Thất bại', 'TIMED_OUT' => 'Quá hạn'];
@endphp
<style>
    .om-wrap{max-width:1500px;margin:0 auto;padding:4px 0 35px}.om-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:16px}.om-head h1{margin:0;color:#0f172a;font-size:27px;font-weight:850}.om-head p{margin:5px 0 0;color:#64748b}.om-live{display:flex;align-items:center;gap:7px;color:#047857;font-size:12px;font-weight:800}.om-live:before{content:"";width:9px;height:9px;border-radius:50%;background:#10b981;box-shadow:0 0 0 5px #d1fae5}.om-capacity{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:16px 18px;margin-bottom:15px;border:1px solid;border-radius:14px}.om-capacity.healthy{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}.om-capacity.warning{background:#fffbeb;border-color:#fde68a;color:#92400e}.om-capacity.danger{background:#fef2f2;border-color:#fecaca;color:#991b1b}.om-capacity strong{display:block;font-size:15px}.om-capacity span{font-size:12px}.om-capacity-badge{white-space:nowrap;padding:7px 10px;border-radius:999px;background:rgba(255,255,255,.65);font-size:11px;font-weight:850}.om-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:15px}.om-card{min-width:0;padding:14px;background:#fff;border:1px solid #e2e8f0;border-radius:13px;box-shadow:0 6px 18px rgba(15,23,42,.035)}.om-card span{display:block;color:#64748b;font-size:11px;font-weight:700}.om-card strong{display:block;margin-top:5px;color:#0f172a;font-size:22px;line-height:1.1}.om-card small{display:block;margin-top:5px;color:#94a3b8;font-size:10px}.om-panels{display:grid;grid-template-columns:1.1fr .9fr;gap:12px;margin-bottom:15px}.om-panel{padding:16px 18px;background:#fff;border:1px solid #e2e8f0;border-radius:14px}.om-panel h2{margin:0 0 13px;color:#0f172a;font-size:15px;font-weight:800}.om-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.om-metric span{display:block;color:#64748b;font-size:11px}.om-metric strong{display:block;margin-top:3px;color:#1e293b;font-size:15px}.om-progress{height:8px;margin-top:14px;overflow:hidden;border-radius:999px;background:#e2e8f0}.om-progress span{display:block;height:100%;border-radius:999px;background:#2563eb;transition:width .35s}.om-table-card{overflow:hidden;background:#fff;border:1px solid #e2e8f0;border-radius:14px}.om-table-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e2e8f0}.om-table-head h2{margin:0;font-size:15px;font-weight:800}.om-updated{color:#64748b;font-size:11px}.om-scroll{overflow:auto;max-height:540px}.om-table{width:100%;min-width:1150px;border-collapse:collapse}.om-table th{position:sticky;top:0;z-index:2;padding:10px 12px;background:#f8fafc;color:#64748b;font-size:10px;text-align:left}.om-table td{padding:10px 12px;border-top:1px solid #edf2f7;color:#334155;font-size:12px;vertical-align:top}.om-table a{color:#2563eb;font-weight:800}.om-sub{display:block;margin-top:2px;color:#94a3b8;font-size:10px}.om-status{display:inline-flex;padding:4px 7px;border-radius:999px;background:#e2e8f0;color:#475569;font-size:10px;font-weight:800}.om-status.PROCESSING{background:#dbeafe;color:#1d4ed8}.om-status.COMPLETED{background:#d1fae5;color:#047857}.om-status.FAILED,.om-status.TIMED_OUT{background:#fee2e2;color:#b91c1c}.om-error{max-width:240px;color:#b91c1c}.om-empty{padding:28px!important;text-align:center;color:#64748b!important}@media(max-width:1200px){.om-grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:800px){.om-grid{grid-template-columns:repeat(2,1fr)}.om-panels{grid-template-columns:1fr}.om-head,.om-capacity{align-items:flex-start;flex-direction:column}.om-wrap{padding-top:0}}
</style>

<div class="om-wrap">
    <header class="om-head">
        <div><h1>Giám sát OCR realtime</h1><p>Đo công suất, thời gian từng ảnh và khả năng hoàn thành trước {{ $summary['deadline'] }} GMT+7.</p></div>
        <div class="om-live">Tự cập nhật mỗi 5 giây</div>
    </header>

    <section id="capacityPanel" class="om-capacity {{ $capacityLevel }}">
        <div><strong id="capacityMessage">{{ data_get($summary, 'capacity.message') }}</strong><span id="capacityDetail">Tốc độ hiện tại {{ $summary['hourly_rate'] }} ảnh/giờ · cần {{ $summary['required_hourly_rate'] }} ảnh/giờ</span></div>
        <div id="capacityBadge" class="om-capacity-badge">{{ $capacityLabels[$capacityLevel] ?? $capacityLevel }}</div>
    </section>

    <section class="om-grid">
        <div class="om-card"><span>Ảnh nhận hôm nay</span><strong id="receivedToday">{{ number_format($summary['received_today']) }}</strong><small>Job được tạo GMT+7</small></div>
        <div class="om-card"><span>Đã xử lý hôm nay</span><strong id="processedToday">{{ number_format($summary['processed_today']) }}</strong><small id="processedBreakdown">{{ $summary['completed_today'] }} đạt · {{ $summary['exception_today'] }} ngoại lệ</small></div>
        <div class="om-card"><span>Còn chờ</span><strong id="backlog">{{ number_format($summary['backlog']) }}</strong><small id="backlogDetail">{{ $summary['processing'] }} đang chạy · {{ $summary['retrying'] }} retry</small></div>
        <div class="om-card"><span>Tốc độ thực tế</span><strong id="hourlyRate">{{ $summary['hourly_rate'] }}/h</strong><small id="rateDetail">{{ $summary['completed_15m'] }} ảnh/15 phút</small></div>
        <div class="om-card"><span>Trung bình/lượt</span><strong id="averageDuration">—</strong><small id="percentileDuration">P50 — · P95 —</small></div>
        <div class="om-card"><span>ETA hết hàng đợi</span><strong id="eta">—</strong><small id="projectedFinish">Chưa đủ dữ liệu</small></div>
    </section>

    <section class="om-panels">
        <div class="om-panel">
            <h2>Tiến độ trong ngày</h2>
            <div class="om-metrics">
                <div class="om-metric"><span>Hoàn thành</span><strong id="completedToday">{{ number_format($summary['completed_today']) }}</strong></div>
                <div class="om-metric"><span>Ngoại lệ</span><strong id="exceptionToday">{{ number_format($summary['exception_today']) }}</strong></div>
                <div class="om-metric"><span>Thất bại</span><strong id="failedToday">{{ number_format($summary['failed_today']) }}</strong></div>
            </div>
            <div class="om-progress"><span id="dailyProgress" style="width:0"></span></div>
        </div>
        <div class="om-panel">
            <h2>Thời gian OCR hôm nay</h2>
            <div class="om-metrics">
                <div class="om-metric"><span>Tổng thời gian</span><strong id="totalRuntime">—</strong></div>
                <div class="om-metric"><span>Nhanh nhất</span><strong id="minimumDuration">—</strong></div>
                <div class="om-metric"><span>Lâu nhất</span><strong id="maximumDuration">—</strong></div>
            </div>
        </div>
    </section>

    <section class="om-table-card">
        <div class="om-table-head"><h2>Nhật ký xử lý gần nhất</h2><span id="lastUpdated" class="om-updated">Đang kết nối…</span></div>
        <div class="om-scroll"><table class="om-table"><thead><tr><th>THỜI GIAN</th><th>JOB/ẢNH</th><th>NGUỒN</th><th>CÔNG ĐOẠN</th><th>CHỜ</th><th>XỬ LÝ</th><th>TỔNG ẢNH</th><th>TRẠNG THÁI</th><th>THÔNG TIN</th></tr></thead><tbody id="runRows"></tbody></table></div>
    </section>
</div>

<script>
(() => {
    const endpoint = @json(route('ocr-monitoring.status'));
    const capacityLabels = {healthy:'Đủ công suất',warning:'Sát ngưỡng',danger:'Không kịp ngày'};
    const stageLabels = {CLASSIFY:'Phân loại',TIMEMARK:'TimeMark',JOURNAL:'Nhật trình'};
    const statusLabels = {PROCESSING:'Đang chạy',COMPLETED:'Hoàn thành',FAILED:'Thất bại',TIMED_OUT:'Quá hạn'};
    const number = value => new Intl.NumberFormat('vi-VN').format(Number(value || 0));
    const escapeHtml = (value = '') => String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
    const duration = ms => {
        ms = Number(ms || 0); if (!ms) return '—';
        const seconds = Math.round(ms / 1000); if (seconds < 60) return `${seconds}s`;
        const minutes = Math.floor(seconds / 60), remain = seconds % 60;
        if (minutes < 60) return `${minutes}m ${remain}s`;
        return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
    };
    const clock = value => value ? new Intl.DateTimeFormat('vi-VN',{timeZone:'Asia/Ho_Chi_Minh',hour:'2-digit',minute:'2-digit',second:'2-digit',day:'2-digit',month:'2-digit'}).format(new Date(value)) : '—';
    const setText = (id, value) => { const node = document.getElementById(id); if (node) node.textContent = value; };

    const renderSummary = summary => {
        const capacity = summary.capacity || {};
        const panel = document.getElementById('capacityPanel'); panel.className = `om-capacity ${capacity.level || 'healthy'}`;
        setText('capacityMessage', capacity.message || 'Đang tính công suất…');
        setText('capacityDetail', `Tốc độ hiện tại ${summary.hourly_rate} ảnh/giờ · cần ${summary.required_hourly_rate} ảnh/giờ`);
        setText('capacityBadge', capacityLabels[capacity.level] || capacity.level);
        setText('receivedToday', number(summary.received_today)); setText('processedToday', number(summary.processed_today));
        setText('processedBreakdown', `${number(summary.completed_today)} đạt · ${number(summary.exception_today)} ngoại lệ`);
        setText('backlog', number(summary.backlog)); setText('backlogDetail', `${number(summary.processing)} đang chạy · ${number(summary.retrying)} retry`);
        setText('hourlyRate', `${summary.hourly_rate}/h`); setText('rateDetail', `${number(summary.completed_15m)} ảnh/15 phút`);
        setText('averageDuration', duration(summary.runtime.average_ms)); setText('percentileDuration', `P50 ${duration(summary.runtime.p50_ms)} · P95 ${duration(summary.runtime.p95_ms)}`);
        setText('eta', summary.eta_minutes === null ? 'Chưa rõ' : duration(summary.eta_minutes * 60000));
        setText('projectedFinish', summary.projected_finish_at ? `Dự kiến ${clock(summary.projected_finish_at)}` : 'Chưa đủ dữ liệu');
        setText('completedToday', number(summary.completed_today)); setText('exceptionToday', number(summary.exception_today)); setText('failedToday', number(summary.failed_today));
        setText('totalRuntime', duration(summary.runtime.total_ms)); setText('minimumDuration', duration(summary.runtime.minimum_ms)); setText('maximumDuration', duration(summary.runtime.maximum_ms));
        const denominator = Math.max(Number(summary.received_today), Number(summary.processed_today), 1);
        document.getElementById('dailyProgress').style.width = `${Math.min(100, Number(summary.processed_today) / denominator * 100)}%`;
    };

    const renderRuns = runs => {
        const rows = document.getElementById('runRows');
        if (!runs.length) { rows.innerHTML = '<tr><td colspan="9" class="om-empty">Chưa có lượt OCR mới sau khi bật đo công suất.</td></tr>'; return; }
        rows.innerHTML = runs.map(run => `<tr>
            <td>${escapeHtml(clock(run.started_at))}<span class="om-sub">Lần ${number(run.attempt)}</span></td>
            <td><a href="${escapeHtml(run.url || '#')}">Job #${number(run.job_id)}</a><span class="om-sub">${escapeHtml(run.asset_code || 'Chưa rõ máy')}</span></td>
            <td>${escapeHtml(run.sender_name || 'Không rõ người gửi')}<span class="om-sub">Nhóm ${escapeHtml(run.group_id || '—')}</span></td>
            <td>${escapeHtml(stageLabels[run.stage] || run.stage)}<span class="om-sub">${escapeHtml(run.worker_id)}</span></td>
            <td>${duration(run.queue_wait_ms)}</td><td><strong>${duration(run.duration_ms)}</strong></td><td>${duration(run.total_job_duration_ms)}</td>
            <td><span class="om-status ${escapeHtml(run.status)}">${escapeHtml(statusLabels[run.status] || run.status)}</span></td>
            <td class="om-error">${escapeHtml(run.error_message || run.document_type || '—')}</td>
        </tr>`).join('');
    };

    let loading = false;
    const refresh = async () => {
        if (loading || document.hidden) return; loading = true;
        try {
            const response = await fetch(endpoint,{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok) throw new Error('Không tải được dữ liệu OCR.');
            const data = await response.json(); renderSummary(data.summary); renderRuns(data.runs || []);
            setText('lastUpdated', `Cập nhật ${clock(data.summary.now)} · GMT+7`);
        } catch (error) { setText('lastUpdated', error.message); }
        finally { loading = false; }
    };
    renderSummary(@json($summary)); renderRuns(@json($runs)); refresh(); setInterval(refresh,5000);
})();
</script>
@endsection
