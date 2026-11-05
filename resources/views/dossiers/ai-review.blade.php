<section class="box">
<h2>AI đề xuất — chờ người dùng duyệt</h2>
@if(!$aiAvailable)<p>Cần migration additive Phase18.1 trên database LOCAL đã xác minh và sao lưu trước khi dùng AI. Nhập tay Phase18 vẫn dùng được.</p>
@else
@unless(app(\App\Services\Dossiers\Ai\DossierVision::class)->probe()['configured'])<p><strong>Chưa cấu hình AI</strong> — chưa gọi API; nhập tay vẫn dùng được.</p>@endunless
<p>AI không xác nhận giấy, liên kết tài xế hay thay đổi phân công. <a href="{{ route('dossiers.ai.rules') }}">Quy tắc hạn nghiệp vụ</a></p>
@if($source->status==='ready')<form method="post" action="{{ route('dossiers.ai.request',$source) }}">@csrf<button>Phân tích AI / kiểm tra cấu hình và xếp lại</button></form>@endif
@if($aiRun)
@php $aiLabels=['unconfigured'=>'Chưa cấu hình AI','queued'=>'Đang xếp hàng','processing'=>'Đang phân tích','ready'=>'Có đề xuất — chưa xác nhận','failed'=>'Có lỗi cần kiểm tra']; @endphp
<p><strong>{{ $aiRun->paused ? 'Đã dừng xếp tiếp' : ($aiLabels[$aiRun->status] ?? $aiRun->status) }}</strong> · {{ $aiRun->pages->where('status','ready')->count() }}/{{ $aiRun->pages->count() }} trang · {{ $aiRun->pipeline }}</p>
@if($aiRun->status==='unconfigured')<p>Điền DOSSIERS_AI_ENDPOINT, DOSSIERS_AI_API_KEY, DOSSIERS_AI_MODEL trong dossier-worker/.env trên máy chạy worker hồ sơ; bật DOSSIERS_AI_ENABLED trên Laravel rồi bấm Phân tích AI. Chưa gọi API, chưa có kết quả. Nhập thủ công vẫn dùng được.</p>@else
<p>{{ $aiRun->context['provider'] ?? '' }} / {{ $aiRun->context['model'] ?? '' }} · <a href="{{ route('dossiers.source',$source) }}">Cập nhật tiến độ</a>. Chi phí chưa tính: chưa cấu hình bảng giá đã xác minh.</p>
@php $usageRows=$aiRun->pages->pluck('usage')->filter()->push($aiRun->group_usage ?? []); @endphp
<p>Usage API cung cấp: prompt {{ $usageRows->sum(fn($u)=>(int)($u['prompt_tokens']??0)) }}, completion {{ $usageRows->sum(fn($u)=>(int)($u['completion_tokens']??0)) }} tokens (không có số liệu không đồng nghĩa0token thực tế). Thời gian tác vụ đã lưu {{ number_format(($aiRun->pages->sum('elapsed_ms')+($aiRun->group_ms??0))/1000,1) }} giây.</p>
<form method="post" action="{{ route('dossiers.ai.control',$aiRun) }}">@csrf<button name="action" value="pause">Dừng xếp tiếp sau tác vụ đang chạy</button><button name="action" value="resume">Xếp tiếp / retry phần lỗi</button></form>
@endif
@if($aiRun->source_revision!==$source->revision)<p class="warn">Dữ liệu hồ sơ đã đổi từ lúc xếp AI. Đề xuất được giữ riêng; phải đối chiếu lại, không tự ghi đè sửa tay.</p>@endif
@if($aiRun->error)<p class="warn">{{ $aiRun->error }}</p>@endif
<details><summary>Kết quả/trang lỗi và đọc lại rõ hơn</summary>
@foreach($aiRun->pages as $ap)<div class="box">Trang {{ $ap->page }}: {{ $ap->status }} · {{ $ap->dpi }}dpi · lần {{ $ap->attempts }} {{ $ap->error }}
@if($ap->result)<p>{{ $ap->result['kind'] }} / xoay gợi ý {{ $ap->result['rotation'] }}° · {{ implode('; ',$ap->result['issues']??[]) }}</p><a href="{{ route('dossiers.ai.image',[$aiRun,$ap->page]) }}" target="dossier-evidence">Xem ảnh đọc rõ</a>@endif
@if($aiRun->status!=='unconfigured')<form method="post" action="{{ route('dossiers.ai.control',$aiRun) }}">@csrf<input type="hidden" name="page" value="{{ $ap->page }}"><button name="action" value="retry">Đọc lại riêng trang ở300dpi</button></form>@endif</div>@endforeach
</details>
@if($aiRun->status==='ready' && $aiRun->group_result)
@php $group=$aiRun->group_result['data']??[]; @endphp
<p>Chưa phân loại: {{ implode(', ',$group['unclassified_pages']??[]) }}.</p>
@foreach($group['suggested_exclusions']??[] as $ex)<p>Đề xuất bỏ trang {{ $ex['page'] }}: {{ $ex['reason'] }}. Chưa tự bỏ; chọn thủ công ở đối chiếu nguồn.</p>@endforeach
@endif
@if($aiRun->status==='ready')
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px">
<div>
@foreach($aiRun->proposals->where('generation',$aiRun->generation) as $proposal)
@php
 $d=$proposal->data;$f=$d['fields'];$value=fn($key)=>$f[$key]['value']??'';
 $expiry=$f['expiry']??null;$precision=$expiry['precision']??($d['permanent_explicit']?'permanent':'unknown');
 $effective=$value('effective_at');if(strlen($effective)===10)$effective.=' 00:00:00';
@endphp
<div class="box"><h3>{{ $d['title'] }} · trang {{ implode(',',$d['pages']) }}</h3>
<p>Chủ thể AI đọc: {{ $value('person_name') ?: $d['subject'] }}. {{ $d['scope_text'] }}</p>
@if(($d['replacement_candidates']??[]) && $source->machine_id)<p>Có {{ count($d['replacement_candidates']) }} chuỗi giấy cùng loại trên máy. <a href="{{ route('dossiers.machine',$source->machine_id) }}" target="_blank" rel="noopener">Đối chiếu giấy hiện có</a>, giữ đúng chủ thể/phạm vi phụ nếu thay giấy; chưa tự thay phiên bản.</p>@endif
@if($d['driver_candidates']??[])<p>Tài xế trùng tên để đối chiếu: #{{ implode(', #',$d['driver_candidates']) }}. Chưa chọn/gán chủ thể.</p>@endif
@foreach($proposal->issues as $issue)<p class="warn">{{ $issue }}</p>@endforeach
<details><summary>Bằng chứng từng trường</summary>@foreach($f as $key=>$e)<p><a href="{{ route('dossiers.ai.image',[$aiRun,$e['page']]) }}" target="dossier-evidence">{{ $key }} · trang {{ $e['page'] }}</a>: {{ $e['value'] }} · “{{ $e['quote'] }}” @if(!empty($e['box']))(vùng {{ implode(',',$e['box']) }})@endif</p>@endforeach</details>
@if($d['insured'])<details><summary>Danh sách BIC/phạm vi — phải đối chiếu đúng người</summary>@foreach($d['insured'] as $person)<p><a href="{{ route('dossiers.ai.image',[$aiRun,$person['page']]) }}" target="dossier-evidence">{{ $person['name'] }}</a> — {{ $person['quote'] }}</p>@endforeach</details>@endif
@if($proposal->version_id)<p>Đã duyệt thành giấy #{{ $proposal->version_id }}. Sửa/thay qua phần Giấy tờ đã lưu, không áp lại AI.</p>
@elseif(empty($d['multiple_documents']))
<form class="ai-proposal-form" data-id="{{ $proposal->id }}" method="post" action="{{ route('dossiers.ai.review',$proposal) }}">@csrf
<input type="hidden" name="source_revision" value="{{ $source->revision }}">
<p><label><input class="bulk-pick" type="checkbox"> Chọn mục này để duyệt nhiều mục</label></p>
<div class="fields">
<label>Loại giấy <select name="type_id" required><option value="">Chọn đúng loại</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected($t->id===($d['type_id']??null))>{{ $t->name }}</option>@endforeach</select></label>
<label>Chủ thể <select name="subject" class="ai-subject"><option value="machine" @selected($d['subject']==='machine')>Máy/xe</option><option value="driver" @selected($d['subject']==='driver')>Tài xế</option><option value="assignment" @selected($d['subject']==='assignment')>Phân công</option></select></label>
<label>Chọn chủ thể đã đối chiếu <select name="subject_id" class="ai-subject-id" required><option value="">Chọn đúng chủ thể</option><option data-subject="machine" value="{{ $source->machine_id }}">{{ $source->machine?->asset_code }}</option>@foreach($drivers as $driver)<option data-subject="driver" value="{{ $driver->id }}">{{ $driver->name }} #{{ $driver->id }}</option>@endforeach @foreach($assignments as $a)<option data-subject="assignment" value="{{ $a->id }}">Phân công #{{ $a->id }} {{ $a->project?->name }}</option>@endforeach</select></label>
<label>Phạm vi phụ (giữ đúng tên nếu thay giấy)<input name="instance" value="main" required></label>
<label>Trang theo thứ tự<input name="page_numbers" value="{{ implode(',',$d['pages']) }}" required></label>
<label>Số giấy<input name="number" value="{{ $value('number') }}" maxlength="255"></label>
<label>Đơn vị cấp/bảo hiểm<input name="issuer" value="{{ $value('issuer') }}" maxlength="255"></label>
<label>Ngày cấp<input type="date" name="issued_on" value="{{ strlen($value('issued_on'))===10?$value('issued_on'):'' }}"></label>
<label>Ngày ký kết luận sức khỏe<input type="date" name="health_signed_on" value="{{ strlen($value('health_signed_on'))===10?$value('health_signed_on'):'' }}"></label>
<label>Ngày chứng thực<input type="date" name="certified_on" value="{{ strlen($value('certified_on'))===10?$value('certified_on'):'' }}"></label>
<label>Hiệu lực phiên bản (VN)<input type="datetime-local" step="1" name="effective_at" value="{{ str_replace(' ','T',$effective) }}" required><small>Nếu giấy không ghi, người dùng chọn thời điểm áp dụng phiên bản; không dùng ngày chứng thực.</small></label>
<label>Độ chính xác hạn<select name="expiry_precision">@foreach(['unknown'=>'Chưa rõ','day'=>'Ngày','minute'=>'Giờ/phút','month'=>'Tháng/năm','permanent'=>'Không thời hạn'] as $k=>$label)<option value="{{ $k }}" @selected($precision===$k)>{{ $label }}</option>@endforeach</select></label>
<label>Hạn trên giấy<input name="expiry_value" value="{{ $expiry['value']??'' }}" placeholder="YYYY-MM-DD / YYYY-MM / YYYY-MM-DD HH:MM:SS"></label>
<label>Diễn giải tháng<select name="month_rule"><option value="">Chưa xác nhận</option><option value="end_of_month">Tôi xác nhận hết ngày cuối tháng</option></select></label>
<label>Ngày cần làm lại/khám lại riêng<input type="date" name="renewal_on"><small>Không thay hạn giấy.</small></label>
</div>
<details><summary>Xoay trang bản xử lý/xuất</summary>@foreach($d['pages'] as $pn)<label>Trang {{ $pn }} <select name="rotations[{{ $pn }}]">@foreach([0,90,180,270] as $angle)<option value="{{ $angle }}" @selected(($aiRun->pages->firstWhere('page',$pn)?->result['rotation']??0)===$angle)>{{ $angle }}°</option>@endforeach</select></label>@endforeach</details>
@if($rule=$d['expiry_suggestion'])<div class="box"><strong>Tính theo quy tắc — chờ xác nhận</strong><p>{{ $rule['value'] }} · {{ $rule['anchor'] }}={{ $rule['anchor_value'] }} · phiên bản {{ $rule['version'] }} · {{ $rule['scope'] }}</p><label><input type="checkbox" name="use_rule" value="1"> Dùng hạn tính thay vì nhập tay</label><label><input type="checkbox" name="rule_scope_confirmed" value="1"> Đủ căn cứ và đúng phạm vi quy tắc này</label></div>@else<p>Không có hạn tính hợp lệ: giữ hạn đọc được hoặc Chưa xác định; quy tắc chưa bật không được tự áp.</p>@endif
<label><input type="checkbox" name="shared_pages" value="1"> Dùng chung có chủ đích (cần xác nhận trên các giấy dùng chung)</label>
<label><input type="checkbox" name="acknowledge_stale" value="1"> Đã đối chiếu lại kết quả AI với sửa tay hiện tại</label>
<label><input type="checkbox" name="reviewed_evidence" value="1" required> Đã đối chiếu scan, loại giấy, các ngày, đúng người và phạm vi</label>
<label>Lý do thêm/thay/sửa<input name="reason" required maxlength="2000"></label>
<label>Ghi chú<textarea name="note" maxlength="5000"></textarea></label>
<button name="confirmed" value="0">Duyệt thành nháp</button><button name="confirmed" value="1">Xác nhận giấy đã kiểm tra</button>
</form>
@endif</div>
@endforeach
<button type="button" id="ai-bulk">Xác nhận các mục đã chọn và đối chiếu</button><p id="ai-bulk-status" role="status"></p>
</div><aside style="position:sticky;top:8px;align-self:start"><iframe title="Scan và bằng chứng" name="dossier-evidence" src="{{ route('dossiers.file',[$source,'source']) }}" style="width:100%;height:80vh;border:1px solid #ccc"></iframe></aside>
</div>
@endif
@endif
@endif
</section>
<script>
document.querySelectorAll('.ai-proposal-form').forEach(form=>{const kind=form.querySelector('.ai-subject'),select=form.querySelector('.ai-subject-id');function update(){for(const o of select.options){if(o.dataset.subject){o.hidden=o.dataset.subject!==kind.value;o.disabled=o.hidden;}}select.value='';}kind.onchange=update;update();});
@if($aiRun)
document.getElementById('ai-bulk')?.addEventListener('click',async()=>{const status=document.getElementById('ai-bulk-status');const forms=[...document.querySelectorAll('.ai-proposal-form')].filter(f=>f.querySelector('.bulk-pick').checked);if(!forms.length){status.textContent='Chọn ít nhất một mục.';return;}if(forms.some(f=>!f.reportValidity()))return;const items=forms.map(f=>{const data=Object.fromEntries(new FormData(f));data.rotations={};for(const [k,v]of new FormData(f)){const m=k.match(/^rotations\[(\d+)\]$/);if(m)data.rotations[m[1]]=v;}data.confirmed=true;return{id:Number(f.dataset.id),data};});try{const r=await fetch(@json(route('dossiers.ai.bulk',$aiRun)),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},body:JSON.stringify({items})});const result=await r.json();if(r.ok)location.reload();else status.textContent=result.message||'Chưa lưu: kiểm tra thông tin từng mục.';}catch{status.textContent='Không nhận được phản hồi; tải lại kiểm tra trước khi thử lại.';}});
@endif
</script>
