@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
<header class="d-header"><h1>{{ $source->original_name }}</h1><a class="d-button secondary" href="{{ route('dossiers.batch',$source->batch_id) }}">Đợt nhập</a></header>
@if($source->replacement_version_id)
<p>Bản thay cho giấy #{{ $source->replacement_version_id }} · {{ $source->replacementVersion->record->type->name }}</p>
@if($source->replacement_cancelled_at)<p>Đã hủy bản thay.</p>
@elseif(!$versions->contains('confirmed',true))<form method="post" action="{{ route('dossiers.sources.cancel-replacement',$source) }}">@csrf<button>Hủy bản thay</button></form>@endif
@endif
@include('dossiers.progress',['progressUrl'=>route('dossiers.source',[$source,'progress'=>1])])
@if($source->confirmed)<p>Đã xác nhận hồ sơ</p>@endif
@unless($source->replacement_cancelled_at)@include('dossiers.ai-review')@endunless
<details class="d-panel"><summary>File gốc và thông tin kỹ thuật</summary>
<small>SHA256: {{ $source->sha256 }} · {{ number_format($source->bytes) }} bytes · nguồn giữ nguyên</small>
@if($source->processed_path)<div class="box"><p>Bản xử lý tương thích: {{ $source->processing['method'] }}. Không raster hóa/hạ độ phân giải; kiểm tra độ rõ trước khi xác nhận giấy.</p>@if($source->processing['warnings'] ?? [])<p>Đã phục hồi cảnh báo thiếu entry của xref stream; bản xử lý đã qua kiểm tra cú pháp, số trang, xoay và thử xuất/mở PDF.</p>@endif<a href="{{ route('dossiers.file',[$source,'processed']) }}" target="_blank" rel="noopener">Mở PDF xử lý để đối chiếu nguồn</a><small>SHA256 bản xử lý: {{ $source->processed_sha256 }}</small></div>@endif
<p><a href="{{ route('dossiers.file',[$source,'source']) }}" target="_blank" rel="noopener">Xem PDF nguồn / tải bản gốc</a></p>
</details>
<details class="d-panel" @if($source->status==='ready' && !$aiRun) open @endif><summary>Đối chiếu và phân loại thủ công</summary>
@if($source->status==='ready' && !$source->confirmed && !$source->replacement_cancelled_at)
<form class="box" method="post" action="{{ route('dossiers.source.save',$source) }}">@csrf<input type="hidden" name="revision" value="{{ $source->revision }}">
@if($source->document_target || $source->replacement_version_id)
<p>Chủ thể đã chọn từ hồ sơ. Đối chiếu bản scan trước khi xác nhận.</p>
@else
<p>Đối chiếu từ tên: {{ $source->resolution }}. {{ implode(', ',$source->candidates) }}. Hãy kiểm tra nội dung có đúng máy trước khi xác nhận.</p>
<label>Máy đúng <select name="machine_id" required><option value="">Chọn máy</option>@foreach($machines as $m)<option value="{{ $m->id }}" @selected($source->machine_id===$m->id)>{{ $m->asset_code }} / {{ $m->machine_type }}</option>@endforeach</select></label>
@endif
<details><summary>Đánh dấu trang bỏ khỏi bộ xuất (không xóa nguồn)</summary>@for($p=1;$p<=$source->page_count;$p++)<label><input type="checkbox" name="excluded_pages[]" value="{{ $p }}" @checked(in_array($p,$source->excluded_pages ?? []) || (!$versions->contains(fn($v)=>in_array($p,array_column($v->pages,'page'))) && ($aiRun?->pages->firstWhere('page',$p)?->result['kind'] ?? null)==='blank'))> Trang {{ $p }}</label>@endfor</details>
<button name="confirm" value="0">Lưu đối chiếu / nháp hồ sơ</button><button name="confirm" value="1">Xác nhận toàn hồ sơ này</button>
</form>
@endif
@php($uses=$versions->flatMap(fn($v)=>array_column($v->pages,'page'))->countBy())
<div class="pages">@for($p=1;$p<=$source->progress;$p++)<div class="page"><a target="_blank" rel="noopener" href="{{ route('dossiers.file',[$source,'thumbnail',$p]) }}"><img loading="lazy" src="{{ route('dossiers.file',[$source,'thumbnail',$p]) }}" alt="Trang {{ $p }}"></a><label><input type="checkbox" class="pick-page" value="{{ $p }}"> Trang {{ $p }}</label><small>{{ in_array($p,$source->excluded_pages ?? []) ? 'Đã đánh dấu bỏ' : (($uses[$p] ?? 0) ? 'Đã dùng '.$uses[$p].' lần' : 'Chưa phân loại') }}</small></div>@endfor</div>
<h2>Giấy tờ đã lưu</h2>
@foreach($versions as $v)<details class="box"><summary>#{{ $v->id }} {{ $v->record->type->name }} — {{ $v->record->driver?->name ?: ($v->record->assignment_id ? 'Phân công #'.$v->record->assignment_id : $source->machine?->asset_code) }} — {{ $v->confirmed ? 'Đã xác nhận' : 'Nháp' }} — trang {{ implode(',',array_column($v->pages,'page')) }}</summary>
@if(!$v->confirmed && !$source->confirmed)@include('dossiers.version-form',['draft'=>$v,'replacement'=>null])@else<p>{{ $v->number }} / {{ $v->expiry_value }} / {{ $v->reason }}</p><p>Thông tin xác nhận được giữ trong lịch sử. Thay giấy bằng phiên bản mới cùng loại/chủ thể/phạm vi phụ.</p><a href="{{ route('dossiers.source',[$source,'replace'=>$v->id]) }}">Tạo phiên bản sửa thông tin / hạn từ giấy này</a>@endif
</details>@endforeach
@if($source->status==='ready' && $source->machine_confirmed && !$source->confirmed && !$source->replacement_cancelled_at)<h2>Phân loại giấy mới / phiên bản thay thế</h2><p>Chọn các trang ở trên hoặc nhập danh sách theo thứ tự. Giấy cá nhân phải chọn đúng từng tài xế.</p>@include('dossiers.version-form',['draft'=>null])@endif
@if($source->status==='ready' && $source->confirmed && $replacement)<h2>Phiên bản sửa thông tin từ #{{ $replacement->id }}</h2><p>Nhập lý do mới và thời điểm hiệu lực sau bản trước. Lưu sẽ mở lại hồ sơ nguồn để kiểm tra; bản cũ vẫn giữ nguyên. Nếu đổi chủ thể/loại/phạm vi phụ: khi xác nhận, chuỗi giấy cũ sẽ được lưu trữ có audit; chuỗi đúng được tạo riêng, chỉ áp dụng ngay hoặc trong quá khứ.</p>@include('dossiers.version-form',['draft'=>null,'replacement'=>$replacement])@endif
</details>
</main>
<script>
document.querySelectorAll('.version-form').forEach(form=>{const kind=form.querySelector('.subject-kind'), select=form.querySelector('.subject-id');function update(reset){for(const o of select.options){o.hidden=o.dataset.subject!==kind.value;o.disabled=o.hidden;}if(reset||!select.selectedOptions.length||select.selectedOptions[0].disabled){select.selectedIndex=[...select.options].findIndex(o=>!o.disabled);}}kind.onchange=()=>update(true);update(false);});
document.querySelectorAll('.pick-page').forEach(box=>box.onchange=()=>{const form=[...document.querySelectorAll('.version-form')].at(-1);if(form)form.querySelector('[name="page_numbers"]').value=[...document.querySelectorAll('.pick-page:checked')].map(x=>x.value).join(',');});
</script>
@endsection
