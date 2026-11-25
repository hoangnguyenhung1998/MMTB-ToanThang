@php
    $key = $v->id;
    $title = $r['type']->name;
    $ocr = $paper?->data['information_pages'] ?? [];
    $suggestion = $paper?->data['fields']['expiry'] ?? null;
@endphp
<dialog class="machine-dialog machine-menu" id="actions-{{ $key }}" aria-labelledby="actions-title-{{ $key }}">
<header><h3 id="actions-title-{{ $key }}">Thao tác · {{ $title }}</h3><button type="button" class="secondary" data-close-paper aria-label="Đóng">×</button></header>
<div class="machine-menu-items">
<button type="button" class="secondary" data-open-paper="type-{{ $key }}">Đổi loại</button>
<button type="button" class="secondary" data-open-paper="pages-{{ $key }}">Sửa trang</button>
@if($tracked && $v->expiry_precision!=='unknown')<button type="button" class="secondary" data-open-paper="expiry-{{ $key }}">Nhập hạn</button>@endif
<a class="d-button secondary" href="{{ route('dossiers.versions.replace',$v) }}">Thay file</a>
<button type="button" class="secondary d-danger" data-open-paper="delete-{{ $key }}">Xóa</button>
</div>
</dialog>

<dialog class="machine-dialog" id="type-{{ $key }}" aria-labelledby="type-title-{{ $key }}">
<header><h3 id="type-title-{{ $key }}">Đổi loại · {{ $title }}</h3><button type="button" class="secondary" data-close-paper aria-label="Đóng">×</button></header>
<form method="post" action="{{ route('dossiers.versions.correct',$v) }}">@csrf
<input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}">
<label>Loại mới <select name="type_id" required><option value="">Chọn loại</option>@foreach($editTypes as $type)
@if($type->supportsGroup($group) && $type->id!==$r['type']->id)<option value="{{ $type->id }}">{{ $type->name }}</option>@endif
@endforeach</select></label>
<label>Lý do <input name="reason" required maxlength="2000"></label>
<footer><button>Lưu loại</button></footer>
</form></dialog>

@if($tracked)
<dialog class="machine-dialog" id="expiry-{{ $key }}" aria-labelledby="expiry-title-{{ $key }}">
<header><h3 id="expiry-title-{{ $key }}">Nhập hạn · {{ $title }}</h3><button type="button" class="secondary" data-close-paper aria-label="Đóng">×</button></header>
<form method="post" action="{{ route('dossiers.versions.correct',$v) }}" data-machine-expiry>@csrf
<input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}"><input type="hidden" name="type_id" value="{{ $r['type']->id }}">
<input type="hidden" name="reason" value="Cập nhật hạn trên hồ sơ máy">
<fieldset><legend>Độ chính xác</legend><div class="machine-controls">
@foreach(['day'=>'Ngày đầy đủ','month'=>'Tháng/năm','permanent'=>'Không có hạn'] as $precision=>$label)
<label><input type="radio" name="expiry_precision" value="{{ $precision }}" @checked($precision===(in_array($v->expiry_precision,['month','permanent'],true)?$v->expiry_precision:'day'))> {{ $label }}</label>
@endforeach
</div></fieldset>
<label data-expiry-mode="day">Hạn đến ngày <input type="date" name="expiry_value" value="{{ $v->expiry_precision==='day' ? $v->expiry_value : '' }}" required></label>
<label data-expiry-mode="month" hidden>Hạn theo tháng/năm <input type="month" name="expiry_value" value="{{ $v->expiry_precision==='month' ? $v->expiry_value : '' }}" disabled required></label>
@if($suggestion)<p>OCR đề xuất {{ $suggestion['value'] }}. Đối chiếu giấy trước khi lưu.</p>@endif
<footer><button>Lưu hạn</button></footer>
</form></dialog>
@endif

<dialog class="machine-dialog machine-page-dialog" id="pages-{{ $key }}" aria-labelledby="pages-title-{{ $key }}">
<header><h3 id="pages-title-{{ $key }}">Sửa trang · {{ $title }}</h3><button type="button" class="secondary" data-close-paper aria-label="Đóng">×</button></header>
<form method="post" action="{{ $paper ? route('dossiers.ai.pages',$paper) : route('dossiers.versions.correct',$v) }}" data-page-editor>@csrf
@if(!$paper)
<input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}"><input type="hidden" name="type_id" value="{{ $r['type']->id }}">
<input type="hidden" name="reason" value="Sửa nhóm và thứ tự trang">
@if($tracked)<input type="hidden" name="expiry_precision" value="{{ $v->expiry_precision }}"><input type="hidden" name="expiry_value" value="{{ $v->expiry_value }}"><input type="hidden" name="month_rule" value="{{ $v->month_rule }}">@endif
@endif
<input type="hidden" name="page_order" value="{{ implode(',',array_column($v->pages,'page')) }}">
@if($paper)<input type="hidden" name="transfer" value="0">@endif
<div class="machine-page-strip" aria-label="Trang của giấy"></div>
<label>Chọn trang từ PDF gốc <select data-source-page>
@for($number=1;$number<=$v->source->page_count;$number++)
<option value="{{ $number }}" data-thumbnail="{{ route('dossiers.file',[$v->source_id,'thumbnail',$number]) }}">Trang {{ $number }}</option>
@endfor
</select></label>
<div class="machine-controls">
<button type="button" class="secondary" data-page-action="add">Thêm trang</button>
@if($paper)<button type="button" class="secondary" data-page-action="transfer">Chuyển trang vào giấy</button>@endif
<button type="button" class="secondary d-danger" data-page-action="remove">Bỏ trang</button>
<button type="button" class="secondary" data-page-action="up" aria-label="Đưa trang lên">↑</button>
<button type="button" class="secondary" data-page-action="down" aria-label="Đưa trang xuống">↓</button>
</div>
<small role="status" data-page-note></small>
<footer><button>Lưu thay đổi</button></footer>
</form></dialog>

<dialog class="machine-dialog" id="delete-{{ $key }}" aria-labelledby="delete-title-{{ $key }}">
<header><h3 id="delete-title-{{ $key }}">Xóa · {{ $title }}</h3><button type="button" class="secondary" data-close-paper aria-label="Đóng">×</button></header>
<form method="post" action="{{ route('dossiers.versions.archive',$v) }}">@csrf
<input type="hidden" name="archived" value="1"><label>Lý do <input name="reason" required maxlength="2000"></label>
<footer><button class="secondary d-danger">Xác nhận xóa giấy này</button></footer>
</form></dialog>

@if($candidates->isNotEmpty())
<dialog class="machine-dialog" id="new-{{ $key }}" aria-labelledby="new-title-{{ $key }}">
<header><h3 id="new-title-{{ $key }}">Có bản mới · {{ $title }}</h3><button type="button" class="secondary" data-close-paper aria-label="Đóng">×</button></header>
<div class="machine-dialog-body">
@foreach($candidates as $candidate)
@include('dossiers.machine-candidate',['paper'=>$candidate])
@endforeach
</div></dialog>
@endif
@if($tracked && $paper)
@if(in_array('pending',$ocr,true))<small>Đang chờ OCR</small>
@elseif(in_array('stale',$ocr,true))<small>Giấy đã sửa trong khi OCR chạy</small>
@elseif(in_array('failed',$ocr,true))<small>OCR chưa đọc được</small>@endif
@endif
