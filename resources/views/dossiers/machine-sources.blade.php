@php
    $visibleVersions = $rows->filter(fn ($row) => $row['version']?->confirmed && ! $row['version']->archived && $row['status'] !== 'future');
    $otherPapers = $papers->whereNull('version_id')->reject(fn ($paper) => $unassigned->contains('id',$paper->id) ||
        (($paper->data['publication'] ?? null) === 'candidate' && $visibleVersions->contains(fn ($row) =>
            $row['type']->id === $paper->data['type_id'] && $row['subject'] === $paper->data['subject'] &&
            ($row['subject'] !== 'driver' || $row['subject_id'] === ($paper->data['driver_id'] ?? null)))));
@endphp
@if($otherPapers->isNotEmpty())
<section class="d-panel"><h2>Giấy cần đối chiếu</h2>
@include('dossiers.machine-pending',['pending'=>$otherPapers,'pendingId'=>null])
</section>
@endif
@foreach($sources as $source)
@php
    $run=$source->latestAiRun;
    $used=$run?->proposals->where('generation',$run->generation)->flatMap(fn($paper)=>$paper->data['pages'])->all() ?? [];
    $blank=$run?->pages->filter(fn($page)=>($page->result['kind']??null)==='blank')->pluck('page')->all() ?? [];
    $remaining=array_diff(range(1,$source->page_count),$used,$blank,$source->excluded_pages??[]);
@endphp
@continue(!$run)
<section class="d-panel">
<div class="d-toolbar"><h2>{{ $source->original_name }}</h2><a href="{{ route('dossiers.file',[$source,'source']) }}">Xem PDF gốc</a>
<form method="post" action="{{ route('dossiers.ai.publish',$run) }}">@csrf<button class="secondary">Lưu từ phân loại có sẵn</button></form></div>
<details class="machine-fold"><summary>Chưa phân loại · {{ count($remaining) }} trang</summary>
@if($remaining)
<form method="post" action="{{ route('dossiers.ai.classify',$run) }}">@csrf
<div class="d-toolbar">
@foreach($remaining as $number)
<label><input type="checkbox" name="pages[]" value="{{ $number }}"><a href="{{ route('dossiers.file',[$source,'thumbnail',$number]) }}" target="_blank" rel="noopener">Trang {{ $number }}</a></label>
@endforeach
</div>
<label>Chọn loại thủ công <select name="type_id"><option value="">Chọn loại</option>@foreach($editTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label>
<label>Thêm vào giấy <select name="existing_proposal"><option value="">Chọn giấy</option>
@foreach($run->proposals->where('generation',$run->generation) as $paper)<option value="{{ $paper->id }}">{{ $paper->data['title'] }} · {{ implode(', ', $paper->data['pages']) }}</option>@endforeach
</select></label>
<button>Chọn loại thủ công / Thêm vào giấy</button>
<button class="secondary" formaction="{{ route('dossiers.ai.white-pages',$run) }}" name="blank" value="1">Đánh dấu trang trắng</button>
</form>
@endif
</details>
<details class="machine-fold"><summary>Trang trắng · {{ count($blank) }} trang</summary>
@if($blank)<form method="post" action="{{ route('dossiers.ai.white-pages',$run) }}">@csrf
@foreach($blank as $number)<label><input type="checkbox" name="pages[]" value="{{ $number }}"> Trang {{ $number }}</label>@endforeach
<button class="secondary" name="blank" value="0">Khôi phục</button>
</form>@endif
</details>
</section>
@endforeach
