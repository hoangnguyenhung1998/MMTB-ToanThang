@extends('layouts.app')

@section('content')
@php
    $skipLabels = [
        'protected' => 'Protected / reviewed / confirmed',
        'already_resolved' => 'Đã resolved hoặc không còn Manual',
        'ai_processing' => 'AI đang chờ / xử lý',
        'ai_already_resolved' => 'AI đã xử lý thành công',
        'ai_unresolved_previous' => 'AI trước đó vẫn cần kiểm tra thủ công',
        'ai_failed_previous' => 'AI trước đó thất bại',
        'missing_source' => 'Thiếu ảnh gốc',
        'other_skipped' => 'Không còn đủ điều kiện khác',
    ];
@endphp
<div class="page-shell ai-preview-page">
    <header class="page-header">
        <div>
            <div class="page-eyebrow">PHASE 16.11.2 · DRY-RUN</div>
            <h1 class="page-title">Preview AI Rescue</h1>
            <p class="page-subtitle">Preview này chỉ đọc dữ liệu. Trạng thái sẽ được kiểm tra lại khi xác nhận.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('ocr-reviews.index') }}">Hủy</a>
    </header>

    <section class="app-card ai-preview-card">
        <div class="ocr-card-head"><strong>Nhóm lỗi đã chọn</strong></div>
        <div class="ai-selected-groups">
            @foreach($preview['reason_labels'] as $label)<span>{{ $label }}</span>@endforeach
        </div>
        <div class="ai-preview-summary">
            <div><span>Tổng records matched</span><strong>{{ number_format($preview['matched_records']) }}</strong></div>
            <div><span>Ảnh unique</span><strong>{{ number_format($preview['unique_photos']) }}</strong></div>
            <div class="will-run"><span>Sẽ OCR bằng AI</span><strong>{{ number_format($preview['will_queue']) }}</strong></div>
        </div>
        <div class="ai-skip-list">
            <strong>Bỏ qua</strong>
            @foreach($skipLabels as $key => $label)
                <div><span>{{ $label }}</span><b>{{ number_format($preview['counts'][$key]) }}</b></div>
            @endforeach
        </div>
        <div class="ai-preview-actions">
            <a class="btn btn-outline-secondary" href="{{ route('ocr-reviews.index') }}">Hủy</a>
            <form method="POST" action="{{ route('ocr-reviews.ai-rescue.execute') }}" id="aiExecuteForm">
                @csrf
                <input type="hidden" name="preview_token" value="{{ $preview['preview_token'] }}">
                <button class="btn btn-primary" type="submit" id="aiExecuteButton" @disabled($preview['will_queue'] === 0)>OCR AI {{ number_format($preview['will_queue']) }} ảnh</button>
            </form>
        </div>
    </section>
</div>
<style>
.ai-preview-card{max-width:850px;margin:0 auto;overflow:hidden}.ai-selected-groups{display:flex;flex-wrap:wrap;gap:7px;padding:14px}.ai-selected-groups span{padding:6px 9px;border-radius:999px;background:#eef4ff;color:#2558c7;font-size:11px;font-weight:800}.ai-preview-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border)}.ai-preview-summary div{display:flex;min-height:95px;flex-direction:column;gap:8px;padding:17px;background:#fff}.ai-preview-summary span{color:#64748b;font-size:11px}.ai-preview-summary strong{font-size:27px}.ai-preview-summary .will-run{background:#eefbf5}.ai-preview-summary .will-run strong{color:#13734d}.ai-skip-list{padding:16px}.ai-skip-list>strong{display:block;margin-bottom:8px}.ai-skip-list div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eef2f7;font-size:12px}.ai-preview-actions{display:flex;justify-content:flex-end;gap:9px;padding:14px 16px;border-top:1px solid var(--border);background:#f8fafc}@media(max-width:600px){.ai-preview-summary{grid-template-columns:1fr}.ai-preview-actions{align-items:stretch;flex-direction:column}.ai-preview-actions form,.ai-preview-actions button{width:100%}}
</style>
<script>
document.getElementById('aiExecuteForm')?.addEventListener('submit', function () {
    const button = document.getElementById('aiExecuteButton');
    button.disabled = true;
    button.textContent = 'Đang xếp hàng...';
});
</script>
@endsection
