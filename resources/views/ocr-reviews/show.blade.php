@extends('layouts.app')

@section('content')
@php
    $statusLabels = ['PENDING' => 'Chờ xử lý', 'PROCESSING' => 'Đang xử lý', 'RETRY' => 'Chờ thử lại', 'COMPLETED' => 'Hoàn thành', 'EXCEPTION' => 'Cần hậu kiểm', 'FAILED' => 'Thất bại'];
    $typeLabels = ['UNKNOWN' => 'Chưa phân loại', 'DAILY_TIMEMARK' => 'Ảnh hằng ngày', 'WEEKLY_JOURNAL' => 'Nhật trình tuần'];
    $labelException = fn (string $code) => $exceptionLabels[$code] ?? $code;
    $document = $job->journalDocument;
    $aiAttemptStatusLabels = ['PENDING' => 'Đang chờ', 'RETRY' => 'Đang chờ thử lại', 'PROCESSING' => 'Đang xử lý', 'COMPLETED' => 'Đã xử lý', 'FAILED' => 'Thất bại', 'SKIPPED' => 'Bỏ qua'];
    $aiResolutionLabels = ['RESOLVED' => 'Thành công', 'HUMAN_REQUIRED' => 'Cần kiểm tra thủ công', 'NON_DAILY' => 'Không phải ảnh Daily', 'FAILED' => 'Thất bại', 'SKIPPED_PROTECTED' => 'Bỏ qua — ảnh được bảo vệ', 'SKIPPED_STALE' => 'Bỏ qua — không còn eligible'];
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

    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if($aiRescueState)
    <section class="app-card ai-rescue-detail-card">
        <div class="ocr-card-head">
            <div><strong>AI Rescue</strong><span>{{ $aiRescueState['message'] }}</span></div>
            @if($aiRescueState['eligible'])
                <form method="POST" action="{{ route('ocr-reviews.ai-rescue.single', $job) }}" class="ai-single-form">
                    @csrf
                    <button class="btn btn-primary" type="submit">OCR lại bằng AI</button>
                </form>
            @elseif($aiRescueState['latest'])
                <span class="ai-detail-badge ai-{{ strtolower($aiRescueState['latest']->status) }}">{{ $aiResolutionLabels[$aiRescueState['latest']->final_resolution] ?? $aiAttemptStatusLabels[$aiRescueState['latest']->status] ?? $aiRescueState['latest']->status }}</span>
            @endif
        </div>
        @if($aiRescueState['latest'])
        @php($latestAi = $aiRescueState['latest'])
        <div class="ai-latest-grid">
            <div><span>Classification</span><strong>{{ $latestAi->classification ?: '—' }}</strong></div>
            <div><span>Máy</span><strong>{{ $latestAi->extracted_machine ?: '—' }}</strong></div>
            <div><span>Ngày</span><strong>{{ $latestAi->extracted_date?->format('d/m/Y') ?: '—' }}</strong></div>
            <div><span>Giờ</span><strong>{{ $latestAi->extracted_time ? substr($latestAi->extracted_time, 0, 5) : '—' }}</strong></div>
            <div><span>Kết quả cuối</span><strong>{{ $aiResolutionLabels[$latestAi->final_resolution] ?? $latestAi->final_resolution ?? 'Đang xử lý' }}</strong></div>
            <div><span>Provider / model</span><strong>{{ $latestAi->provider ?: '—' }}{{ $latestAi->model ? ' · '.$latestAi->model : '' }}</strong></div>
        </div>
        @if($latestAi->error_message || data_get($latestAi->validation_outcome, 'reasons'))
            <div class="ai-attempt-note">{{ $latestAi->error_message ?: implode(', ', data_get($latestAi->validation_outcome, 'reasons', [])) }}</div>
        @endif
        @endif
        @if($aiRescueState['history']->isNotEmpty())
        <details class="ai-history">
            <summary>Lịch sử AI Rescue ({{ $aiRescueState['history']->count() }})</summary>
            <div class="table-scroll"><table class="table table-modern"><thead><tr><th>Attempt</th><th>Thời gian</th><th>Trạng thái</th><th>Classification</th><th>Máy</th><th>Ngày / giờ</th><th>Kết quả</th><th>Model</th><th>Lỗi / lý do</th></tr></thead><tbody>
            @foreach($aiRescueState['history'] as $attempt)
                <tr>
                    <td>#{{ $attempt->attempts }}</td>
                    <td>{{ $attempt->requested_at?->format('d/m/Y H:i:s') ?: '—' }}</td>
                    <td>{{ $aiAttemptStatusLabels[$attempt->status] ?? $attempt->status }}</td>
                    <td>{{ $attempt->classification ?: '—' }}</td>
                    <td>{{ $attempt->extracted_machine ?: '—' }}</td>
                    <td>{{ $attempt->extracted_date?->format('d/m/Y') ?: '—' }} {{ $attempt->extracted_time ? substr($attempt->extracted_time, 0, 5) : '' }}</td>
                    <td>{{ $aiResolutionLabels[$attempt->final_resolution] ?? $attempt->final_resolution ?? '—' }}</td>
                    <td>{{ $attempt->model ?: '—' }}</td>
                    <td>{{ $attempt->error_message ?: implode(', ', data_get($attempt->validation_outcome, 'reasons', [])) ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody></table></div>
        </details>
        @endif
    </section>
    @endif
    @if(config('daily_photos.enabled') && !$job->reviewed_at && $job->status !== 'PROCESSING')
    <form class="d-flex gap-2 mb-3" method="POST" action="{{ route('daily-photos.requeue', $job) }}">
        @csrf
        <button class="btn btn-outline-primary" name="document_type" value="DAILY_TIMEMARK">Ảnh ngày — OCR lại</button>
        <button class="btn btn-outline-secondary" name="document_type" value="WEEKLY_JOURNAL">Ảnh nhật trình — tạm giữ</button>
        <button class="btn btn-outline-secondary" name="document_type" value="UNKNOWN">Ảnh khác — không OCR</button>
    </form>
    @endif
    @if(data_get($job->daily_metadata, 'near_duplicate_ids'))<div class="alert alert-warning">Ảnh có thể gần trùng với job {{ implode(', ', $job->daily_metadata['near_duplicate_ids']) }}. Giữ ảnh gốc để kiểm tra.</div>@endif
    @if ($job->document_type === 'DAILY_TIMEMARK')
    <div class="daily-review-workspace">
        <div class="daily-editor-column">
        <section class="app-card daily-review-form">
            <div class="ocr-card-head"><strong>Chỉnh sửa ảnh hằng ngày</strong><span>Chỉnh sửa ngoại lệ khi cần</span></div>
            <form method="POST" action="{{ route('ocr-reviews.update', $job) }}">
            @csrf @method('PUT')
            <div class="daily-form-grid">
                <label><span>Mã máy xác nhận</span><select name="machine_id">@foreach($machines as $machine)<option value="{{ $machine->id }}" @selected($job->machine_id === $machine->id)>{{ $machine->asset_code }}</option>@endforeach</select></label>
                <label><span>Ngày</span><input type="date" name="extracted_date" value="{{ $job->extracted_date?->format('Y-m-d') }}"></label>
                <label><span>Giờ 24 giờ</span><input type="text" inputmode="numeric" placeholder="HH:mm" pattern="(?:[01]\d|2[0-3]):[0-5]\d" name="extracted_time" value="{{ $job->extracted_time ? substr($job->extracted_time,0,5) : '' }}"></label>
                <label><span>Người vận hành</span><input name="operator_name" value="{{ $job->operator_name }}"></label>
                <label><span>Số điện thoại</span><input name="phone" value="{{ $job->phone }}"></label>
                <label class="daily-wide-field"><span>Vị trí</span><textarea name="work_location" rows="2">{{ $job->work_location }}</textarea></label>
                <label class="daily-wide-field"><span>Ghi chú hậu kiểm</span><textarea name="review_notes" rows="2">{{ $job->review_notes }}</textarea></label>
                <label><span>Nhóm case OCR</span><select name="case_category">@foreach(\App\Models\OcrRegressionCase::CATEGORIES as $category)<option value="{{ $category }}">{{ $category }}</option>@endforeach</select></label>
                <label><span>Kết quả mong đợi</span><select name="expected_disposition"><option value="DAILY_TIMEMARK">Ảnh hằng ngày</option><option value="IGNORED_HOUR_METER">Bỏ qua — đồng hồ giờ</option><option value="IGNORED_NON_DAILY_PHOTO">Bỏ qua — ảnh khác</option></select></label>
            </div>
            <div class="daily-review-actions">

                <button class="btn btn-primary" name="action" value="correct">Lưu chỉnh sửa</button>
                <button class="btn btn-outline-primary" name="action" value="correct_and_add_case">Lưu + thêm vào bộ case OCR</button>
                <button class="btn btn-danger" name="action" value="reject">Từ chối</button>
            </div>
            </form>
        </section>
        <section class="app-card ocr-result-card">
            <div class="ocr-card-head"><strong>Kết quả nhận dạng hiện tại</strong></div>
            <div class="ocr-daily-grid">
                <div><span>Ngày</span><strong>{{ $job->extracted_date?->format('d/m/Y') ?: '—' }}</strong></div>
                <div><span>Giờ</span><strong>{{ $job->extracted_time ? substr($job->extracted_time, 0, 5) : '—' }}</strong></div>
                <div><span>Ca</span><strong>{{ $job->shift ?: '—' }}</strong></div>
                <div><span>Độ tin cậy</span><strong>{{ $job->confidence !== null ? number_format((float) $job->confidence * 100, 1).'%' : '—' }}</strong></div>
                <div><span>Người gửi</span><strong>{{ $job->attachment?->message?->sender_name ?: '—' }}</strong></div>
                <div><span>Vị trí</span><strong>{{ $job->work_location ?: '—' }}</strong></div>
            </div>
        </section>
        </div>
        <section class="app-card ocr-image-card weekly-image-panel">
            <div class="ocr-card-head"><strong>Ảnh gốc đối chiếu</strong><div class="image-tools"><button type="button" data-image-action="left" title="Xoay trái">↶</button><button type="button" data-image-action="right" title="Xoay phải">↷</button><button type="button" data-image-action="out" title="Thu nhỏ">−</button><button type="button" data-image-action="in" title="Phóng to">+</button><button type="button" data-image-action="reset">Đặt lại</button></div></div>
            @if ($imageExists)<div class="image-viewport"><img id="reviewSourceImage" src="{{ route('ocr-reviews.image', $job) }}" alt="Ảnh hằng ngày job {{ $job->id }}"></div>@else<div class="ocr-missing-image">Không tìm thấy file ảnh trên máy chủ hiện tại.</div>@endif
        </section>
    </div>
    @endif

    @if ($job->document_type === 'WEEKLY_JOURNAL' && $document)
    <div class="weekly-review-workspace">
        @include('ocr-reviews._journal-editor', ['document' => $document])
        <section class="app-card ocr-image-card weekly-image-panel">
            <div class="ocr-card-head">
                <strong>Ảnh gốc đối chiếu</strong>
                <div class="image-tools">
                    <button type="button" data-image-action="left" title="Xoay trái">↶</button>
                    <button type="button" data-image-action="right" title="Xoay phải">↷</button>
                    <button type="button" data-image-action="out" title="Thu nhỏ">−</button>
                    <button type="button" data-image-action="in" title="Phóng to">+</button>
                    <button type="button" data-image-action="reset">Đặt lại</button>
                </div>
            </div>
            @if ($imageExists)
                <div class="image-viewport"><img id="reviewSourceImage" src="{{ route('ocr-reviews.image', $job) }}" alt="Ảnh nhật trình job {{ $job->id }}"></div>
            @else
                <div class="ocr-missing-image">Không tìm thấy file ảnh trên máy chủ hiện tại.</div>
            @endif
        </section>
    </div>
    <div class="weekly-context-grid">
        <section class="app-card ocr-info-card">
            <div class="ocr-card-head"><strong>Thông tin nhận dạng</strong></div>
            <dl class="ocr-meta-list">
                <div><dt>Mã máy</dt><dd>{{ $job->asset_code ?: $document->asset_code ?: 'Chưa nhận dạng' }}</dd></div>
                <div><dt>Thiết bị khớp</dt><dd>{{ $job->machine?->asset_code ?: '—' }}</dd></div>
                <div><dt>Độ tin cậy</dt><dd>{{ $job->confidence !== null ? number_format((float) $job->confidence * 100, 1).'%' : '—' }}</dd></div>
                <div><dt>Người gửi</dt><dd>{{ $job->attachment?->message?->sender_name ?: '—' }}</dd></div>
            </dl>
        </section>
        <section class="app-card ocr-exception-card">
            <div class="ocr-card-head"><strong>Ngoại lệ cần kiểm tra</strong></div>
            @if (!empty($job->exceptions))
                <ul>@foreach ($job->exceptions as $exception)<li>{{ $labelException($exception) }}</li>@endforeach</ul>
            @else
                <p>Không có ngoại lệ cấp tài liệu.</p>
            @endif
        </section>
    </div>
    @elseif ($job->document_type !== 'DAILY_TIMEMARK')
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
    @endif

    @if ($job->raw_text)
        <details class="app-card ocr-raw-card"><summary>Văn bản OCR nguyên bản</summary><pre>{{ $job->raw_text }}</pre></details>
    @endif
</div>

<style>
.ocr-detail-status{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;font-size:11px;font-weight:800}.ocr-status-pending,.ocr-status-retry{background:#fff4cc;color:#8a5b00}.ocr-status-processing{background:#e8efff;color:#2558c7}.ocr-status-completed{background:#e9f8f1;color:#13734d}.ocr-status-exception{background:#fff0d8;color:#a05200}.ocr-status-failed{background:#fff0f1;color:#b42332}.ocr-detail-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,.7fr);gap:16px}.ocr-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 17px;border-bottom:1px solid var(--border)}.ocr-card-head strong{font-size:14px}.ocr-card-head span{color:#64748b;font-size:11px}.ocr-image-card{overflow:hidden}.ocr-image-card img{display:block;width:100%;max-height:700px;object-fit:contain;background:#0f172a}.ocr-missing-image{display:grid;min-height:340px;place-items:center;padding:30px;background:#f8fafc;color:#94a3b8;text-align:center}.ocr-side{display:flex;flex-direction:column;gap:16px}.ocr-info-card,.ocr-exception-card{overflow:hidden}.ocr-meta-list{margin:0;padding:8px 17px 14px}.ocr-meta-list div{display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid #eef2f7}.ocr-meta-list div:last-child{border-bottom:0}.ocr-meta-list dt{color:#64748b;font-size:12px;font-weight:500}.ocr-meta-list dd{margin:0;color:#0f172a;font-size:12px;font-weight:800;text-align:right}.ocr-exception-card ul{display:flex;flex-direction:column;gap:7px;margin:0;padding:14px 34px;color:#9a4b00;font-size:12px}.ocr-exception-card p{margin:0;padding:17px;color:#13734d;font-size:12px}.ocr-exception-card pre{max-height:180px;margin:0;padding:14px;overflow:auto;background:#fff7f8;color:#9f2635;font-size:10px;white-space:pre-wrap}.ocr-result-card{margin-top:16px;overflow:hidden}.ocr-daily-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border)}.ocr-daily-grid div{display:flex;min-height:82px;flex-direction:column;gap:7px;padding:16px;background:#fff}.ocr-daily-grid span{color:#64748b;font-size:11px}.ocr-daily-grid strong{font-size:14px}.weekly-review-workspace,.daily-review-workspace{display:grid;grid-template-columns:minmax(560px,1.1fr) minmax(420px,.9fr);align-items:start;gap:16px}.daily-editor-column{display:grid;gap:16px}.daily-review-form{overflow:hidden}.daily-review-form form{display:grid;gap:14px;padding:14px}.daily-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.daily-form-grid label span{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.daily-form-grid input,.daily-form-grid select,.daily-form-grid textarea{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}.daily-wide-field{grid-column:span 2}.daily-review-actions{display:flex;flex-wrap:wrap;gap:8px}.daily-editor-column .ocr-result-card{margin-top:0}.weekly-image-panel{position:sticky;top:12px}.weekly-context-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:16px}.image-tools{display:flex;align-items:center;gap:5px}.image-tools button{min-width:31px;padding:5px 7px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#334155;font-weight:800;cursor:pointer}.image-viewport{display:grid;height:calc(100vh - 190px);min-height:480px;place-items:center;overflow:auto;background:#0f172a}.image-viewport img{width:100%;height:auto;max-height:none;object-fit:contain;transform-origin:center;transition:transform .15s ease}.journal-table{min-width:1450px}.journal-content{min-width:260px;white-space:normal}.journal-row-alert{background:#fffaf0}.row-exception{display:block;color:#a05200;font-size:10px;font-weight:700}.ocr-raw-card{margin-top:16px;overflow:hidden}.ocr-raw-card summary{cursor:pointer;padding:15px 17px;font-weight:800}.ocr-raw-card pre{max-height:360px;margin:0;padding:17px;overflow:auto;border-top:1px solid var(--border);background:#f8fafc;font-size:11px;white-space:pre-wrap}@media(max-width:1100px){.weekly-review-workspace,.daily-review-workspace,.weekly-context-grid,.ocr-detail-grid{grid-template-columns:1fr}.weekly-image-panel{position:static;grid-row:1}.image-viewport{height:65vh;min-height:360px}.ocr-daily-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.daily-form-grid,.ocr-daily-grid{grid-template-columns:1fr}.daily-wide-field{grid-column:auto}.image-tools{flex-wrap:wrap}}
.ai-rescue-detail-card{margin-bottom:16px;overflow:hidden}.ai-rescue-detail-card .ocr-card-head>div span{display:block;margin-top:3px}.ai-detail-badge{padding:6px 9px;border-radius:999px;background:#eef2f7;font-size:10px;font-weight:800}.ai-latest-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border)}.ai-latest-grid div{display:flex;min-height:76px;flex-direction:column;gap:5px;padding:13px;background:#fff}.ai-latest-grid span{color:#64748b;font-size:10px}.ai-latest-grid strong{font-size:12px;overflow-wrap:anywhere}.ai-attempt-note{padding:10px 14px;border-top:1px solid #f4d7a0;background:#fffaf0;color:#8a5b00;font-size:11px}.ai-history summary{cursor:pointer;padding:12px 16px;border-top:1px solid var(--border);font-size:12px;font-weight:800}.ai-history table{min-width:1150px;margin:0}.ai-single-form{margin:0}@media(max-width:700px){.ai-latest-grid{grid-template-columns:1fr 1fr}}@media(max-width:480px){.ai-latest-grid{grid-template-columns:1fr}.ai-rescue-detail-card .ocr-card-head{align-items:stretch;flex-direction:column}.ai-single-form button{width:100%}}
</style>

@if (in_array($job->document_type, ['DAILY_TIMEMARK', 'WEEKLY_JOURNAL'], true) && $imageExists)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const image = document.getElementById('reviewSourceImage');
    let rotation = 0; let scale = 1;
    const render = () => { image.style.transform = `rotate(${rotation}deg) scale(${scale})`; };
    document.querySelector('.image-tools')?.addEventListener('click', event => {
        const action = event.target.closest('[data-image-action]')?.dataset.imageAction;
        if (!action) return;
        if (action === 'left') rotation -= 90;
        if (action === 'right') rotation += 90;
        if (action === 'in') scale = Math.min(3, scale + .2);
        if (action === 'out') scale = Math.max(.4, scale - .2);
        if (action === 'reset') { rotation = 0; scale = 1; }
        render();
    });
});
</script>
@endif
@endsection
