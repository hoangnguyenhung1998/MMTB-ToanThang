@extends('layouts.app')

@section('content')
@php
    $latest = $job->submissions->first();
    $activeCommand = $job->commands->first(fn ($command) => in_array($command->status, ['PENDING', 'PROCESSING', 'RETRY'], true));
    $actionLabels = [
        'RECONCILE_AGAIN' => 'Đối soát lại', 'DEEP_ANALYSIS' => 'Phân tích sâu',
        'EXPLAIN_RESULT' => 'Giải thích kết quả', 'CHECK_EVIDENCE' => 'Kiểm tra bằng chứng',
        'REVIEW_FINDINGS' => 'Đánh giá findings', 'SUMMARIZE' => 'Tạo bản tóm tắt',
        'GENERAL_ANALYSIS' => 'Yêu cầu phân tích khác',
    ];
@endphp

<div class="ai-detail">
    <header class="ai-detail-head">
        <div><a href="{{ route('ai-reconciliation.index') }}">← Đối soát AI</a><h1>{{ $job->machine?->asset_code ?? 'Máy không xác định' }} · {{ $job->work_date?->format('d/m/Y') }}</h1><p>Job #{{ $job->id }} · {{ $job->status }}</p></div>
        <span class="result-pill result-{{ strtolower($latest?->outcome ?? 'none') }}">{{ $latest?->outcome ?? 'CHƯA CÓ KẾT QUẢ' }}</span>
    </header>

    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="ai-detail-grid">
        <main>
            <section class="app-card detail-card">
                <h2>Kết luận hiện tại</h2>
                <div class="result-grid">
                    <div><span>Kết quả</span><strong>{{ $latest?->outcome ?? '—' }}</strong></div>
                    <div><span>Độ tin cậy</span><strong>{{ $latest?->confidence !== null ? number_format((float) $latest->confidence * 100, 0).'%' : '—' }}</strong></div>
                    <div><span>Xử lý bởi</span><strong>{{ $latest?->agent_name ?? '—' }}</strong></div>
                    <div><span>Thời gian</span><strong>{{ $latest?->submitted_at?->format('d/m/Y H:i:s') ?? '—' }}</strong></div>
                </div>
                <p class="result-summary">{{ $latest?->summary ?: 'Chưa có bản tóm tắt.' }}</p>
            </section>

            <section class="app-card detail-card">
                <h2>Findings <small>{{ $latest?->findings->count() ?? 0 }}</small></h2>
                @forelse ($latest?->findings ?? [] as $finding)
                    <article class="finding finding-{{ strtolower($finding->severity) }}">
                        <div><span>{{ $finding->severity }}</span><strong>{{ $finding->title }}</strong></div>
                        @if ($finding->description)<p>{{ $finding->description }}</p>@endif
                        @if ($finding->suggested_action)<p><b>Đề xuất:</b> {{ $finding->suggested_action }}</p>@endif
                        @if ($finding->evidence)<details><summary>Dữ liệu bằng chứng</summary><pre>{{ json_encode($finding->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
                    </article>
                @empty
                    <div class="empty-box">Không có finding. Với kết quả MATCHED, đây là trạng thái bình thường.</div>
                @endforelse
            </section>

            <section class="app-card detail-card">
                <h2>Bằng chứng đã đối soát</h2>
                <div class="evidence-columns">
                    <div><h3>Ảnh TimeMark ({{ count($evidence['daily_images']) }})</h3>@forelse($evidence['daily_images'] as $image)<a href="{{ route('ocr-reviews.show', $image['ocr_job_id']) }}">OCR #{{ $image['ocr_job_id'] }} · {{ $image['date'] }} {{ $image['time'] }}</a>@empty<span>Chưa có</span>@endforelse</div>
                    <div><h3>Dòng nhật trình ({{ count($evidence['journal_rows']) }})</h3>@forelse($evidence['journal_rows'] as $row)<a href="{{ route('ocr-reviews.show', $row['ocr_job_id']) }}">OCR #{{ $row['ocr_job_id'] }} · {{ $row['start_time'] }}–{{ $row['end_time'] }}</a>@empty<span>Chưa có</span>@endforelse</div>
                </div>
            </section>

            <section class="app-card detail-card">
                <h2>Lịch sử kết quả</h2>
                @foreach ($job->submissions as $submission)
                    <div class="history-row"><strong>{{ $submission->outcome }}</strong><span>{{ $submission->agent_name }}</span><span>{{ $submission->submitted_at?->format('d/m/Y H:i:s') }}</span><span>{{ $submission->findings->count() }} findings</span></div>
                @endforeach
            </section>
        </main>

        <aside>
            <section class="app-card command-card">
                <h2>Yêu cầu OpenClaw</h2>
                <p>Phân tích trong phạm vi job này. OpenClaw không tự sửa dữ liệu hoặc duyệt thay anh.</p>
                @if ($activeCommand)
                    <div class="command-active"><strong>{{ $actionLabels[$activeCommand->action] ?? $activeCommand->action }}</strong><span>{{ $activeCommand->status }}</span><small>Trang sẽ tự cập nhật khi hoàn thành.</small></div>
                @else
                    <form method="POST" action="{{ route('ai-reconciliation.commands.store', $job) }}">
                        @csrf
                        <select name="action" required>
                            @foreach($actionLabels as $action => $label)<option value="{{ $action }}">{{ $label }}</option>@endforeach
                        </select>
                        <textarea name="instruction" rows="6" required placeholder="Ví dụ: Giải thích vì sao ảnh đầu ca được ghép với dòng nhật trình này.">{{ old('instruction') }}</textarea>
                        <button class="btn btn-primary" type="submit">Gửi yêu cầu</button>
                    </form>
                @endif
            </section>

            <section class="app-card command-card">
                <h2>Lịch sử lệnh</h2>
                @forelse($job->commands as $command)
                    <article class="command-history">
                        <div><strong>#{{ $command->id }} · {{ $actionLabels[$command->action] ?? $command->action }}</strong><span>{{ $command->status }}</span></div>
                        <p>{{ $command->instruction }}</p>
                        @if($command->result_summary)<div class="command-result">{{ $command->result_summary }}</div>@endif
                        @if($command->error_message)<div class="command-error">{{ $command->error_message }}</div>@endif
                        <small>{{ $command->user?->name ?? 'Hệ thống' }} · {{ $command->created_at?->format('d/m/Y H:i:s') }}</small>
                    </article>
                @empty<div class="empty-box">Chưa có lệnh nào.</div>@endforelse
            </section>

            <section class="app-card command-card">
                <h2>Lịch sử thông báo</h2>
                @forelse($job->alerts as $alert)
                    <article class="alert-history">
                        <div><strong>{{ $alert->kind }}</strong><span class="alert-status alert-status-{{ strtolower($alert->status) }}">{{ $alert->status }}</span></div>
                        @if(data_get($alert->payload, 'summary'))<p>{{ data_get($alert->payload, 'summary') }}</p>@endif
                        @if($alert->error_message)<div class="command-error">{{ $alert->error_message }}</div>@endif
                        <small>
                            {{ $alert->sent_at ? 'Đã gửi '.$alert->sent_at->format('d/m/Y H:i:s') : 'Tạo '.$alert->created_at?->format('d/m/Y H:i:s') }}
                            · {{ $alert->attempts }} lần thử
                        </small>
                    </article>
                @empty<div class="empty-box">Chưa có thông báo Telegram nào.</div>@endforelse
            </section>
        </aside>
    </div>
</div>

<style>
.ai-detail{max-width:1450px;margin:0 auto}.ai-detail-head{display:flex;align-items:end;justify-content:space-between;margin-bottom:16px}.ai-detail-head a{font-size:12px}.ai-detail-head h1{margin:7px 0 2px;font-size:24px}.ai-detail-head p{margin:0;color:#64748b}.result-pill{padding:8px 12px;border-radius:999px;font-size:11px;font-weight:900}.result-matched{background:#def7ec;color:#087047}.result-warning,.result-waiting_evidence{background:#fff0d8;color:#9a5700}.result-exception{background:#ffe4e8;color:#aa2332}.result-none,.result-unresolved{background:#eef2f7;color:#596579}.ai-detail-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(320px,.8fr);gap:16px}.detail-card,.command-card{padding:18px;margin-bottom:16px}.detail-card h2,.command-card h2{margin:0 0 14px;font-size:16px}.detail-card h2 small{color:#64748b}.result-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.result-grid div{padding:11px;border-radius:10px;background:#f8fafc}.result-grid span{display:block;color:#64748b;font-size:10px}.result-grid strong{display:block;margin-top:4px}.result-summary{margin:14px 0 0;line-height:1.55}.finding{padding:13px;margin-bottom:10px;border:1px solid #dbe4ef;border-left:4px solid #94a3b8;border-radius:10px}.finding-warning{border-left-color:#e5a600}.finding-critical{border-left-color:#dc3545}.finding-info{border-left-color:#3b82f6}.finding>div{display:flex;gap:9px;align-items:center}.finding>div span{font-size:9px;font-weight:900}.finding p{margin:8px 0 0}.finding pre{white-space:pre-wrap;font-size:11px}.evidence-columns{display:grid;grid-template-columns:1fr 1fr;gap:14px}.evidence-columns>div{padding:12px;border:1px solid #dbe4ef;border-radius:10px}.evidence-columns h3{font-size:13px}.evidence-columns a,.evidence-columns span{display:block;margin-top:7px;font-size:12px}.history-row{display:grid;grid-template-columns:1fr 1.5fr 1.5fr 1fr;gap:8px;padding:10px 0;border-bottom:1px solid #edf1f6;font-size:12px}.command-card>p{color:#64748b;font-size:12px}.command-card form{display:grid;gap:10px}.command-active{display:grid;gap:5px;padding:13px;border-radius:10px;background:#eef4ff}.command-active span{font-size:11px;color:#2859b8;font-weight:900}.command-history,.alert-history{padding:12px 0;border-bottom:1px solid #edf1f6}.command-history>div,.alert-history>div{display:flex;justify-content:space-between;gap:8px}.command-history span,.alert-history span{font-size:10px;font-weight:900}.command-history p,.alert-history p,.command-result,.command-error{font-size:12px;line-height:1.45}.command-result{padding:9px;border-radius:8px;background:#f1fbf6}.command-error{padding:9px;border-radius:8px;background:#fff5f6;color:#aa2332}.command-history small,.alert-history small{color:#64748b}.alert-status-sent{color:#087047}.alert-status-retry,.alert-status-pending{color:#9a5700}.alert-status-failed{color:#aa2332}.empty-box{padding:20px;border:1px dashed #cbd5e1;border-radius:10px;color:#64748b;text-align:center}@media(max-width:1050px){.ai-detail-grid{grid-template-columns:1fr}.result-grid{grid-template-columns:1fr 1fr}}@media(max-width:600px){.result-grid,.evidence-columns{grid-template-columns:1fr}}
</style>

@if ($activeCommand)
<script>window.setTimeout(() => window.location.reload(), 5000);</script>
@endif
@endsection
