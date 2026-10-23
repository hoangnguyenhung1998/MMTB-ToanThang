@extends('layouts.app')
@section('content')
<div class="container py-4">
<h1>Xử lý ảnh đang chờ</h1>
<p>Yêu cầu đã được ghi nhận. Bạn có thể đóng trình duyệt; hệ thống tiếp tục xử lý theo lịch nền.</p>
<p>Người gửi: <strong>{{ $run->filters['sender_id'] ?? 'Tất cả trong phạm vi CLI' }}</strong></p>
@foreach($run->filters as $key => $value)<span class="badge bg-secondary">{{ $key }}: {{ $value }}</span> @endforeach
<div class="card card-body mt-3" id="progress" aria-live="polite"></div>
<form method="POST" action="{{ route('daily-photos.recovery.retry', $run) }}" id="retry" hidden>@csrf
<button class="btn btn-warning mt-3">Tiếp tục phần chưa hoàn tất</button></form>
<p class="mt-3">“Đã đưa vào hàng OCR retry” chỉ ghi nhận việc xếp hàng. OCR thành công được theo dõi riêng ở màn hình OCR.</p>
<a href="{{ route('daily-photos.settings') }}">Về ánh xạ người gửi</a>
</div>
<script>
const labels = {QUEUED: 'Đang chờ', RUNNING: 'Đang xử lý', COMPLETED: 'Hoàn tất xử lý yêu cầu', FAILED: 'Có lỗi cần xử lý'};
const names = {examined:'Ảnh đã kiểm tra', recovered:'Phục hồi từ dữ liệu có sẵn', queued_retry:'Đã đưa vào hàng OCR retry', manual:'Cần xử lý thủ công', protected:'Dữ liệu được bảo vệ', ignored:'Ảnh được loại theo phân loại hiện có', changed:'Điều kiện đã thay đổi', paired:'Case đã pairing', synced:'Máy/ngày/kỳ đã sync', downstream_repaired:'Ảnh đã xếp lịch bổ sung downstream'};
function render(run) {
    const box = document.getElementById('progress');
    box.replaceChildren();
    const status = document.createElement('strong'); status.textContent = labels[run.status]; box.append(status);
    for (const [key, value] of Object.entries(run.counters)) {
        const line = document.createElement('div'); line.textContent = (names[key] || key) + ': ' + value; box.append(line);
    }
    if (run.error_code) { const line = document.createElement('div'); line.textContent = 'Lỗi: ' + run.error_code + '; yêu cầu ' + run.id + '; bước ' + JSON.stringify(run.error_context || {}); box.append(line); }
    document.getElementById('retry').hidden = run.status !== 'FAILED';
}
const initial = {{ \Illuminate\Support\Js::from($run->only(['id','status','counters','error_code','error_context'])) }};
render(initial);
async function poll() {
    try {
        const response = await fetch({{ \Illuminate\Support\Js::from(route('daily-photos.recovery.show', $run)) }}, {headers: {'Accept':'application/json'}, cache:'no-store'});
        if (!response.ok) throw new Error('Không đọc được tiến độ');
        const run = await response.json(); render(run);
        if (['COMPLETED','FAILED'].includes(run.status)) return;
    } catch (error) { document.getElementById('progress').title = 'Mất kết nối; yêu cầu vẫn được lưu. Tải lại trang để xem tiến độ.'; }
    setTimeout(poll, 5000);
}
if (!['COMPLETED','FAILED'].includes(initial.status)) setTimeout(poll, 5000);
</script>
@endsection
