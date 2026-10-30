@extends('layouts.app')
@section('content')
<main class="dossier">@include('dossiers.common')
<h1>{{ $source->original_name }}</h1><p>Trạng thái: {{ $source->status }} · {{ $source->progress }}/{{ $source->page_count ?: '?' }} trang · {{ $source->confirmed ? 'Đã xác nhận hồ sơ' : 'Đang phân loại' }}</p>
@if(\Illuminate\Support\Facades\Schema::hasTable('dossier_worker_jobs'))
@php($remoteJob=\App\Models\DossierWorkerJob::where('kind','prepare')->where('subject_id',(string)$source->id)->latest('created_at')->first())
@if($remoteJob && $source->status!=='ready')<p>Worker job: {{ $remoteJob->status }} · {{ $remoteJob->stage }} · đã xử lý {{ $remoteJob->progress }} trang · lần {{ $remoteJob->attempts }}/3</p>@endif
@endif
<small>SHA256: {{ $source->sha256 }} · {{ number_format($source->bytes) }} bytes · nguồn giữ nguyên</small>
@if($source->processed_path)<div class="box"><p>Bản xử lý tương thích: {{ $source->processing['method'] }}. Không raster hóa/hạ độ phân giải; kiểm tra độ rõ trước khi xác nhận giấy.</p>@if($source->processing['warnings'] ?? [])<p>Đã phục hồi cảnh báo thiếu entry của xref stream; bản xử lý đã qua kiểm tra cú pháp, số trang, xoay và thử xuất/mở PDF.</p>@endif<a href="{{ route('dossiers.file',[$source,'processed']) }}" target="_blank" rel="noopener">Mở PDF xử lý để đối chiếu nguồn</a><small>SHA256 bản xử lý: {{ $source->processed_sha256 }}</small></div>@endif
<p><a href="{{ route('dossiers.file',[$source,'source']) }}" target="_blank" rel="noopener">Xem PDF nguồn rõ / tải bản gốc</a> · <a href="{{ route('dossiers.source',$source) }}">Cập nhật tiến độ</a> · <a href="{{ route('dossiers.batch',$source->batch_id) }}">Đợt nhập</a></p>
@if($source->status!=='ready')<div class="box"><p>{{ $source->error ?: 'Đang chờ worker hồ sơ xử lý PDF/thumbnail. Có thể đóng trang rồi mở lại.' }}</p><form method="post" action="{{ route('dossiers.source.retry',$source) }}">@csrf<button>Thử lại / tiếp tục xử lý file</button></form></div>@endif
@if($source->status==='ready' && !$source->confirmed)
<form class="box" method="post" action="{{ route('dossiers.source.save',$source) }}">@csrf<input type="hidden" name="revision" value="{{ $source->revision }}">
<p>Đối chiếu từ tên: {{ $source->resolution }}. {{ implode(', ',$source->candidates) }}. Hãy kiểm tra nội dung có đúng máy trước khi xác nhận.</p>
<label>Máy đúng <select name="machine_id" required><option value="">Chọn máy</option>@foreach($machines as $m)<option value="{{ $m->id }}" @selected($source->machine_id===$m->id)>{{ $m->asset_code }} / {{ $m->machine_type }}</option>@endforeach</select></label>
<details><summary>Đánh dấu trang bỏ khỏi bộ xuất (không xóa nguồn)</summary>@for($p=1;$p<=$source->page_count;$p++)<label><input type="checkbox" name="excluded_pages[]" value="{{ $p }}" @checked(in_array($p,$source->excluded_pages ?? []))> Trang {{ $p }}</label>@endfor</details>
<button name="confirm" value="0">Lưu đối chiếu / nháp hồ sơ</button><button name="confirm" value="1">Xác nhận toàn hồ sơ này</button>
</form>
@endif
@php($uses=$versions->flatMap(fn($v)=>array_column($v->pages,'page'))->countBy())
<div class="pages">@for($p=1;$p<=$source->progress;$p++)<div class="page"><a target="_blank" rel="noopener" href="{{ route('dossiers.file',[$source,'thumbnail',$p]) }}"><img loading="lazy" src="{{ route('dossiers.file',[$source,'thumbnail',$p]) }}" alt="Trang {{ $p }}"></a><label><input type="checkbox" class="pick-page" value="{{ $p }}"> Trang {{ $p }}</label><small>{{ in_array($p,$source->excluded_pages ?? []) ? 'Đã đánh dấu bỏ' : (($uses[$p] ?? 0) ? 'Đã dùng '.$uses[$p].' lần' : 'Chưa phân loại') }}</small></div>@endfor</div>
<h2>Giấy tờ đã lưu</h2>
@include('dossiers.ai-review')
@foreach($versions as $v)<details class="box"><summary>#{{ $v->id }} {{ $v->record->type->name }} — {{ $v->record->driver?->name ?: ($v->record->assignment_id ? 'Phân công #'.$v->record->assignment_id : $source->machine?->asset_code) }} — {{ $v->confirmed ? 'Đã xác nhận' : 'Nháp' }} — trang {{ implode(',',array_column($v->pages,'page')) }}</summary>
@if(!$v->confirmed && !$source->confirmed)@include('dossiers.version-form',['draft'=>$v,'replacement'=>null])@else<p>{{ $v->number }} / {{ $v->expiry_value }} / {{ $v->reason }}</p><p>Thông tin xác nhận được giữ trong lịch sử. Thay giấy bằng phiên bản mới cùng loại/chủ thể/phạm vi phụ.</p><a href="{{ route('dossiers.source',[$source,'replace'=>$v->id]) }}">Tạo phiên bản sửa thông tin / hạn từ giấy này</a>@endif
</details>@endforeach
@if($source->status==='ready' && $source->machine_confirmed && !$source->confirmed)<h2>Phân loại giấy mới / phiên bản thay thế</h2><p>Chọn các trang ở trên hoặc nhập danh sách theo thứ tự. Giấy cá nhân phải chọn đúng từng tài xế.</p>@include('dossiers.version-form',['draft'=>null])@endif
@if($source->status==='ready' && $source->confirmed && $replacement)<h2>Phiên bản sửa thông tin từ #{{ $replacement->id }}</h2><p>Nhập lý do mới và thời điểm hiệu lực sau bản trước. Lưu sẽ mở lại hồ sơ nguồn để kiểm tra; bản cũ vẫn giữ nguyên. Nếu đổi chủ thể/loại/phạm vi phụ: khi xác nhận, chuỗi giấy cũ sẽ được lưu trữ có audit; chuỗi đúng được tạo riêng, chỉ áp dụng ngay hoặc trong quá khứ.</p>@include('dossiers.version-form',['draft'=>null,'replacement'=>$replacement])@endif
</main>
<script>
document.querySelectorAll('.version-form').forEach(form=>{const kind=form.querySelector('.subject-kind'), select=form.querySelector('.subject-id');function update(reset){for(const o of select.options){o.hidden=o.dataset.subject!==kind.value;o.disabled=o.hidden;}if(reset||!select.selectedOptions.length||select.selectedOptions[0].disabled){select.selectedIndex=[...select.options].findIndex(o=>!o.disabled);}}kind.onchange=()=>update(true);update(false);});
document.querySelectorAll('.pick-page').forEach(box=>box.onchange=()=>{const form=[...document.querySelectorAll('.version-form')].at(-1);if(form)form.querySelector('[name="page_numbers"]').value=[...document.querySelectorAll('.pick-page:checked')].map(x=>x.value).join(',');});
</script>
@endsection
