@extends('layouts.app')
@section('content')
<div class="container py-4">
<h1>Tiến độ AI Rescue</h1>
<p>Yêu cầu đã được lưu. Bạn có thể đóng trình duyệt và mở lại; hệ thống tiếp tục kiểm tra và xếp hàng theo lịch nền.</p>
<p>Đã xếp hàng không đồng nghĩa OCR hoàn tất. Các ảnh mới sau preview không được thêm vào yêu cầu.</p>
<div id="ai-progress" aria-live="polite"></div>
<form id="ai-retry" hidden method="POST" action="{{ route('ocr-reviews.ai-rescue.retry', $progress['id']) }}">@csrf<button class="btn btn-warning">Tiếp tục phần xếp hàng bị lỗi</button></form>
<a href="{{ route('ocr-reviews.index') }}">Về Ảnh và ngoại lệ OCR</a>
</div>
<script>
const labels={QUEUED:'Đang chờ kiểm tra',ENQUEUEING:'Đang kiểm tra và xếp hàng',OCR_RUNNING:'Đang chờ / xử lý OCR',COMPLETED:'OCR đã hoàn tất',COMPLETED_WITH_REVIEW:'Đã xử lý yêu cầu; có ảnh cần kiểm tra hoặc bị bỏ qua',FAILED:'Có lỗi xếp hàng cần xử lý'};
const initial={{ \Illuminate\Support\Js::from($progress) }};
function render(run) {
    const box=document.getElementById('ai-progress');box.replaceChildren();
    for(const text of [labels[run.status], 'Ảnh đã chọn: '+run.selected, 'Đã kiểm tra: '+run.selection_done, 'Đã tạo hàng OCR: '+run.enqueued, 'Đã có hàng AI: '+run.already_queued, 'Bỏ qua khi kiểm tra: '+run.skipped, 'Đang chờ OCR: '+run.ocr.queued, 'Đang OCR: '+run.ocr.processing, 'Thành công / phân loại không phải nhật trình: '+run.ocr.succeeded, 'Cần kiểm tra: '+run.ocr.review, 'OCR thất bại: '+run.ocr.failed]) {
        const line=document.createElement('div');line.textContent=text;box.append(line);
    }
    if(run.error_code){const line=document.createElement('div');line.textContent='Lỗi: '+run.error_code+'; ảnh '+run.error_job_id;box.append(line);}
    document.getElementById('ai-retry').hidden=run.status!=='FAILED';
}
render(initial);
async function poll(){
    try {const response=await fetch({{ \Illuminate\Support\Js::from(route('ocr-reviews.ai-rescue.progress', $progress['id'])) }},{headers:{Accept:'application/json'},cache:'no-store'});if(!response.ok)throw new Error();const run=await response.json();render(run);if(['COMPLETED','COMPLETED_WITH_REVIEW','FAILED'].includes(run.status))return;}
    catch(error){document.getElementById('ai-progress').title='Mất kết nối; yêu cầu vẫn được lưu. Tải lại để xem tiến độ.';}
    setTimeout(poll,5000);
}
if(!['COMPLETED','COMPLETED_WITH_REVIEW','FAILED'].includes(initial.status))setTimeout(poll,5000);
</script>
@endsection
