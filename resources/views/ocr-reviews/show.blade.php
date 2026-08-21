@extends('layouts.app')

@section('content')
@php
    $statusLabels = ['PENDING' => 'Chờ xử lý', 'PROCESSING' => 'Đang xử lý', 'RETRY' => 'Chờ thử lại', 'COMPLETED' => 'Hoàn thành', 'EXCEPTION' => 'Cần hậu kiểm', 'FAILED' => 'Thất bại'];
    $typeLabels = ['UNKNOWN' => 'Chưa phân loại', 'DAILY_TIMEMARK' => 'Ảnh hằng ngày', 'WEEKLY_JOURNAL' => 'Nhật trình tuần'];
    $labelException = fn (string $code) => $exceptionLabels[$code] ?? $code;
    $document = $job->journalDocument;
@endphp

<div class="page-shell ocr-detail-page">
    <header class="page-header">
        <div>
            <div class="page-eyebrow">HẬU KIỂM OCR · JOB #{{ $job->id }}</div>
            <h1 class="page-title">{{ $typeLabels[$job->document_type] ?? $job->document_type }}</h1>
            <p class="page-subtitle">Tin nhắn {{ $job->attachment?->message?->message_id ?: '—' }} · {{ $job->attachment?->message?->sent_at?->format('d/m/Y H:i') ?: '—' }}</p>
        </div>
        <div class="page-actions">
            <span class="ocr-detail-status ocr-status-{{ strtolower($job->status) }}">{{ $statusLabels[$job->status] ?? $job->status }}</span>
            <a class="btn btn-outline-secondary" href="{{ route('ocr-reviews.index') }}">Quay lại</a>
        </div>
    </header>

    <div class="ocr-detail-grid">
        <section class="app-card ocr-image-card">
            <div class="ocr-card-head"><strong>Ảnh gốc</strong><span>{{ $job->attachment?->original_name }}</span></div>
            @if ($imageExists)
                <a href="{{ route('ocr-reviews.image', $job) }}" target="_blank" rel="noopener">
                    <img src="{{ route('ocr-reviews.image', $job) }}" alt="Ảnh OCR job {{ $job->id }}">
                </a>
            @else
                <div class="ocr-missing-image">Không tìm thấy file ảnh trên máy chủ hiện tại.</div>
            @endif
        </section>

        <aside class="ocr-side">
            <section class="app-card ocr-info-card">
                <div class="ocr-card-head"><strong>Thông tin nhận dạng</strong></div>
                <dl class="ocr-meta-list">
                    <div><dt>Mã máy</dt><dd>{{ $job->asset_code ?: $document?->asset_code ?: 'Chưa nhận dạng' }}</dd></div>
                    <div><dt>Thiết bị khớp</dt><dd>{{ $job->machine?->asset_code ?: '—' }}</dd></div>
                    <div><dt>Độ tin cậy</dt><dd>{{ $job->confidence !== null ? number_format((float) $job->confidence * 100, 1).'%' : '—' }}</dd></div>
                    <div><dt>Người gửi</dt><dd>{{ $job->attachment?->message?->sender_name ?: '—' }}</dd></div>
                    <div><dt>Số lần thử</dt><dd>{{ $job->attempts }}</dd></div>
                    <div><dt>Xử lý lúc</dt><dd>{{ $job->processed_at?->format('d/m/Y H:i:s') ?: '—' }}</dd></div>
                </dl>
            </section>

            <section class="app-card ocr-exception-card">
                <div class="ocr-card-head"><strong>Ngoại lệ cần kiểm tra</strong></div>
                @if (!empty($job->exceptions))
                    <ul>@foreach ($job->exceptions as $exception)<li>{{ $labelException($exception) }}</li>@endforeach</ul>
                @else
                    <p>Không có ngoại lệ cấp tài liệu.</p>
                @endif
                @if ($job->error_message)<pre>{{ $job->error_message }}</pre>@endif
            </section>
        </aside>
    </div>

    @if ($job->document_type === 'DAILY_TIMEMARK')
        <section class="app-card ocr-result-card">
            <div class="ocr-card-head"><strong>Kết quả ảnh hằng ngày</strong></div>
            <div class="ocr-daily-grid">
                <div><span>Ngày</span><strong>{{ $job->extracted_date?->format('d/m/Y') ?: '—' }}</strong></div>
                <div><span>Giờ</span><strong>{{ $job->extracted_time ? substr($job->extracted_time, 0, 5) : '—' }}</strong></div>
                <div><span>Ca</span><strong>{{ $job->shift ?: '—' }}</strong></div>
                <div><span>Người vận hành</span><strong>{{ $job->operator_name ?: '—' }}</strong></div>
                <div><span>Số điện thoại</span><strong>{{ $job->phone ?: '—' }}</strong></div>
                <div><span>Vị trí</span><strong>{{ $job->work_location ?: '—' }}</strong></div>
            </div>
        </section>
    @elseif ($document)
        <section class="app-card ocr-result-card">
            <div class="ocr-card-head"><strong>Dòng nhật trình</strong><span>{{ $document->rows->count() }} dòng</span></div>
            <div class="table-scroll">
                <table class="table table-modern journal-table">
                    <thead><tr><th>STT</th><th>Ngày</th><th>Bắt đầu</th><th>Kết thúc</th><th>Phút</th><th>Nội dung công việc</th><th>Khối lượng</th><th>Vị trí</th><th>Người vận hành</th><th>Tin cậy</th><th>Ngoại lệ</th></tr></thead>
                    <tbody>
                    @foreach ($document->rows as $row)
                        <tr class="{{ !empty($row->exceptions) ? 'journal-row-alert' : '' }}">
                            <td>{{ $row->row_number }}</td>
                            <td>{{ $row->work_date?->format('d/m/Y') ?: '—' }}</td>
                            <td>{{ $row->start_time ? substr($row->start_time, 0, 5) : '—' }}</td>
                            <td>{{ $row->end_time ? substr($row->end_time, 0, 5) : '—' }}</td>
                            <td>{{ $row->total_minutes ?? '—' }}</td>
                            <td class="journal-content">{{ $row->work_content ?: '—' }}</td>
                            <td>{{ $row->quantity !== null ? rtrim(rtrim(number_format((float) $row->quantity, 2, '.', ''), '0'), '.') : '—' }} {{ $row->unit }}</td>
                            <td>{{ $row->work_location ?: '—' }}</td>
                            <td>{{ $row->operator_name ?: '—' }}</td>
                            <td>{{ number_format((float) $row->confidence * 100, 0) }}%</td>
                            <td>@foreach ($row->exceptions ?? [] as $exception)<span class="row-exception">{{ $labelException($exception) }}</span>@endforeach</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($job->raw_text)
        <details class="app-card ocr-raw-card"><summary>Văn bản OCR nguyên bản</summary><pre>{{ $job->raw_text }}</pre></details>
    @endif
</div>

<style>
.ocr-detail-status{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;font-size:11px;font-weight:800}.ocr-status-pending,.ocr-status-retry{background:#fff4cc;color:#8a5b00}.ocr-status-processing{background:#e8efff;color:#2558c7}.ocr-status-completed{background:#e9f8f1;color:#13734d}.ocr-status-exception{background:#fff0d8;color:#a05200}.ocr-status-failed{background:#fff0f1;color:#b42332}.ocr-detail-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,.7fr);gap:16px}.ocr-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 17px;border-bottom:1px solid var(--border)}.ocr-card-head strong{font-size:14px}.ocr-card-head span{color:#64748b;font-size:11px}.ocr-image-card{overflow:hidden}.ocr-image-card img{display:block;width:100%;max-height:700px;object-fit:contain;background:#0f172a}.ocr-missing-image{display:grid;min-height:340px;place-items:center;padding:30px;background:#f8fafc;color:#94a3b8;text-align:center}.ocr-side{display:flex;flex-direction:column;gap:16px}.ocr-info-card,.ocr-exception-card{overflow:hidden}.ocr-meta-list{margin:0;padding:8px 17px 14px}.ocr-meta-list div{display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid #eef2f7}.ocr-meta-list div:last-child{border-bottom:0}.ocr-meta-list dt{color:#64748b;font-size:12px;font-weight:500}.ocr-meta-list dd{margin:0;color:#0f172a;font-size:12px;font-weight:800;text-align:right}.ocr-exception-card ul{display:flex;flex-direction:column;gap:7px;margin:0;padding:14px 34px;color:#9a4b00;font-size:12px}.ocr-exception-card p{margin:0;padding:17px;color:#13734d;font-size:12px}.ocr-exception-card pre{max-height:180px;margin:0;padding:14px;overflow:auto;background:#fff7f8;color:#9f2635;font-size:10px;white-space:pre-wrap}.ocr-result-card{margin-top:16px;overflow:hidden}.ocr-daily-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border)}.ocr-daily-grid div{display:flex;min-height:82px;flex-direction:column;gap:7px;padding:16px;background:#fff}.ocr-daily-grid span{color:#64748b;font-size:11px}.ocr-daily-grid strong{font-size:14px}.journal-table{min-width:1450px}.journal-content{min-width:260px;white-space:normal}.journal-row-alert{background:#fffaf0}.row-exception{display:block;color:#a05200;font-size:10px;font-weight:700}.ocr-raw-card{margin-top:16px;overflow:hidden}.ocr-raw-card summary{cursor:pointer;padding:15px 17px;font-weight:800}.ocr-raw-card pre{max-height:360px;margin:0;padding:17px;overflow:auto;border-top:1px solid var(--border);background:#f8fafc;font-size:11px;white-space:pre-wrap}@media(max-width:1000px){.ocr-detail-grid{grid-template-columns:1fr}.ocr-daily-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.ocr-daily-grid{grid-template-columns:1fr}}
</style>
@endsection
