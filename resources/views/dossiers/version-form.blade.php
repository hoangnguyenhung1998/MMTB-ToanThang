@php
    $draft = $draft ?? null;
    $values = $draft ?? ($replacement ?? null);
    $record = $values?->record;
    $subject = $record?->driver_id ? 'driver' : ($record?->assignment_id ? 'assignment' : 'machine');
    $subjectId = $record?->driver_id ?? $record?->assignment_id ?? $source->machine_id;
@endphp
<form class="box version-form" method="post" action="{{ $draft ? route('dossiers.versions.save',$draft) : route('dossiers.versions.create') }}">@csrf
<input type="hidden" name="request_id" value="{{ $draft?->request_id ?? (string) Str::uuid() }}"><input type="hidden" name="source_id" value="{{ $source->id }}">
@if($record)<input type="hidden" name="record_id" value="{{ $record->id }}">@endif
@if($replacement ?? $draft?->corrects_id)<input type="hidden" name="corrects_id" value="{{ $replacement?->id ?? $draft?->corrects_id }}">@endif
<div class="fields">
<label>Loại giấy <select name="type_id" required>@foreach($types as $t)<option value="{{ $t->id }}" @selected($record?->type_id===$t->id)>{{ $t->name }}</option>@endforeach</select></label>
<label>Chủ thể <select name="subject" class="subject-kind"><option value="machine" @selected($subject==='machine')>Máy / xe</option><option value="driver" @selected($subject==='driver')>Tài xế</option><option value="assignment" @selected($subject==='assignment')>Phân công / công trình</option></select></label>
<label>Chọn đúng chủ thể <select name="subject_id" class="subject-id" required>
<option data-subject="machine" value="{{ $source->machine_id }}" @selected($subject==='machine')>{{ $source->machine?->asset_code }}</option>
@foreach($drivers as $d)<option data-subject="driver" value="{{ $d->id }}" @selected($subject==='driver' && $subjectId===$d->id)>{{ $d->name }} (#{{ $d->id }})</option>@endforeach
@foreach($assignments as $a)<option data-subject="assignment" value="{{ $a->id }}" @selected($subject==='assignment' && $subjectId===$a->id)>#{{ $a->id }} {{ $a->project?->name }} / {{ $a->commandCenter?->name }} / {{ $a->time_in }}</option>@endforeach
</select></label>
<label>Phạm vi phụ / giấy cùng loại <input name="instance" value="{{ $record?->instance ?? 'main' }}" required maxlength="100"><small>Giữ cùng tên để thay phiên bản; tên khác cho giấy cùng loại độc lập.</small></label>
<label>Trang theo thứ tự (vd 1,2,5) <input name="page_numbers" value="{{ $values ? implode(',',array_column($values->pages,'page')) : '' }}" required></label>
<label><input type="checkbox" name="shared_pages" value="1" @checked($values?->shared_pages)> Các trang được dùng chung có chủ đích</label>
</div>
<details><summary>Xoay riêng từng trang trong bản xuất</summary>@for($p=1;$p<=$source->page_count;$p++)<label>Trang {{ $p }} <select name="rotations[{{ $p }}]">@foreach([0,90,180,270] as $angle)<option value="{{ $angle }}" @selected((collect($values?->pages ?? [])->firstWhere('page',$p)['rotation'] ?? 0) === $angle)>{{ $angle }}°</option>@endforeach</select></label>@endfor</details>
<div class="fields"><label>Số giấy <input name="number" value="{{ $values?->number }}" maxlength="255"></label><label>Đơn vị cấp <input name="issuer" value="{{ $values?->issuer }}" maxlength="255"></label>
<label>Ngày cấp <input type="date" name="issued_on" value="{{ $values?->issued_on }}"></label><label>Ngày chứng thực <input type="date" name="certified_on" value="{{ $values?->certified_on }}"></label>
@if($aiAvailable ?? false)<label>Ngày ký kết luận sức khỏe <input type="date" name="health_signed_on" value="{{ $values?->health_signed_on }}"></label><label>Ngày cần làm lại/khám lại riêng <input type="date" name="renewal_on" value="{{ $values?->renewal_on }}"><small>Không thay hạn giấy.</small></label>@endif
<label>Bắt đầu hiệu lực <input type="datetime-local" name="effective_at" value="{{ $draft ? str_replace(' ','T',substr($draft->effective_at,0,16)) : app(\App\Services\Dossiers\DossierStatus::class)->now()->format('Y-m-d\TH:i') }}" required></label>
<label>Độ chính xác hạn <select name="expiry_precision">@foreach(['unknown'=>'Chưa rõ','day'=>'Ngày','minute'=>'Giờ/phút','month'=>'Tháng/năm','permanent'=>'Không thời hạn'] as $key=>$label)<option value="{{ $key }}" @selected(($values?->expiry_precision ?? 'unknown')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Hạn ghi trên giấy <input name="expiry_value" value="{{ $values?->expiry_value }}" placeholder="YYYY-MM-DD / YYYY-MM-DD HH:MM:SS / YYYY-MM"><small>Không tự suy hạn từ ngày cấp hoặc ngày chứng thực.</small></label>
<label>Diễn giải tháng/năm <select name="month_rule"><option value="">Chưa xác nhận quy tắc</option><option value="end_of_month" @selected($values?->month_rule==='end_of_month')>Tôi xác nhận tính hết ngày cuối tháng</option></select></label>
</div>
<label>Lý do thêm / thay / sửa <input name="reason" value="{{ $draft?->reason }}" required maxlength="2000"></label><label>Ghi chú <textarea name="note" maxlength="5000">{{ $values?->note }}</textarea></label>
<button name="confirmed" value="0">Lưu nháp giấy</button><button name="confirmed" value="1">Xác nhận giấy này</button>
</form>
