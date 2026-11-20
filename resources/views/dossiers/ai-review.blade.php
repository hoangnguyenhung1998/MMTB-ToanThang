<section class="box">
<h2>Phân loại và đối chiếu hồ sơ</h2>
@if(!$aiAvailable)<p>Cần migration additive Phase18.1 trên database LOCAL đã xác minh và sao lưu trước khi dùng AI. Nhập tay Phase18 vẫn dùng được.</p>
@else
@unless(app(\App\Services\Dossiers\Ai\DossierVision::class)->probe()['configured'])<p><strong>Chưa cấu hình AI</strong> — chưa gọi API; nhập tay vẫn dùng được.</p>@endunless
<p>Giấy đủ bằng chứng có thể được xử lý tự động. Mục chưa rõ xuất hiện trong danh sách cần xác nhận. <a href="{{ route('dossiers.ai.rules') }}">Quy tắc hạn nghiệp vụ</a></p>
@if($source->status==='ready')<form method="post" action="{{ route('dossiers.ai.request',$source) }}">@csrf<button>Phân tích AI / kiểm tra cấu hình và xếp lại</button></form>@endif
@if($aiRun)
@php $aiLabels=['unconfigured'=>'Chưa cấu hình AI','queued'=>'Đang xếp hàng','processing'=>'Đang phân tích','ready'=>'Đã phân tích','failed'=>'Có lỗi cần kiểm tra']; @endphp
<p><strong>{{ $aiRun->paused ? 'Đã dừng xếp tiếp' : ($aiLabels[$aiRun->status] ?? $aiRun->status) }}</strong> · {{ $aiRun->pages->where('status','ready')->count() }}/{{ $aiRun->pages->count() }} trang · Run #{{ $aiRun->id }} · {{ $aiRun->pipeline }}</p>
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
@if($ap->result)<p>{{ match($ap->result['kind']) {'blank'=>'Trang trắng — bỏ qua phân tích','unknown'=>'Cần kiểm tra',default=>'Có tài liệu'} }} / xoay gợi ý {{ $ap->result['rotation'] }}° · {{ implode('; ',$ap->result['issues']??[]) }}</p><a href="{{ route('dossiers.ai.image',[$aiRun,$ap->page]) }}" target="dossier-evidence">Xem ảnh đọc rõ</a>@endif
@if($ap->result)<form method="post" action="{{ route('dossiers.ai.label',[$aiRun,$ap->page]) }}">@csrf
<label>Nhãn trang <select name="kind">@foreach(['blank'=>'Trang trắng','document'=>'Có tài liệu','unknown'=>'Cần kiểm tra'] as $key=>$label)
<option value="{{ $key }}" @selected($ap->result['kind']===$key)>{{ $label }}</option>@endforeach</select></label><button>Lưu nhãn</button></form>@endif
@if($aiRun->status!=='unconfigured')<form method="post" action="{{ route('dossiers.ai.control',$aiRun) }}">@csrf<input type="hidden" name="page" value="{{ $ap->page }}"><button name="action" value="retry">Đọc lại riêng trang ở300dpi</button></form>@endif</div>@endforeach
</details>
@if($aiRun->status==='ready' && $aiRun->group_result)
@php $group=$aiRun->group_result['data']??[]; @endphp
<p>Chưa phân loại: {{ implode(', ',$group['unclassified_pages']??[]) }}.</p>
@foreach($group['suggested_exclusions']??[] as $ex)<p>Đề xuất bỏ trang {{ $ex['page'] }}: {{ $ex['reason'] }}. Chưa tự bỏ; chọn thủ công ở đối chiếu nguồn.</p>@endforeach
@endif
@if($aiRun->status==='ready')
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px">
<aside style="position:sticky;top:8px;align-self:start"><iframe title="Scan và bằng chứng" id="dossier-evidence" name="dossier-evidence" src="{{ route('dossiers.file',[$source,'source']) }}" style="width:100%;height:80vh;border:1px solid #ccc"></iframe></aside>
<div>
<h3>Cần xác nhận</h3>
<table><thead><tr><th>Giấy</th><th>Trang nguồn</th><th>Chủ thể</th><th>Hạn đọc được</th><th>Đối chiếu</th></tr></thead><tbody>
@foreach($aiRun->proposals->where('generation',$aiRun->generation)->whereNull('version_id') as $proposal)
@php $comparison=app(\App\Services\Dossiers\Ai\DossierComparison::class)->compare($proposal->data,$source); @endphp
<tr><td><a href="#proposal-{{ $proposal->id }}" data-page="{{ route('dossiers.ai.image',[$aiRun,$proposal->data['pages'][0]]) }}">{{ $proposal->data['title'] }}</a></td>
<td>@foreach($proposal->data['pages'] as $pn)<a href="{{ route('dossiers.ai.image',[$aiRun,$pn]) }}" target="dossier-evidence">{{ $pn }}</a> @endforeach</td>
<td>{{ $proposal->data['fields']['person_name']['value'] ?? $source->machine?->asset_code }}</td>
<td>{{ $proposal->data['fields']['expiry']['value'] ?? 'Chưa rõ hạn' }}</td>
<td>{{ ['match'=>'Khớp','mismatch'=>'Không khớp','missing'=>'Chưa đủ dữ liệu'][$comparison['status']] }}</td></tr>
@endforeach
</tbody></table>
@foreach($aiRun->proposals->where('generation',$aiRun->generation)->whereNull('version_id') as $proposal)
@php
 $d=$proposal->data;$f=$d['fields'];$value=fn($key)=>$f[$key]['value']??'';
 $expiry=$f['expiry']??null;$precision=$expiry['precision']??($d['permanent_explicit']?'permanent':'unknown');
 $effective=app(\App\Services\Dossiers\DossierStatus::class)->now()->format('Y-m-d H:i:s');
 $record=$source->replacementVersion?->record;
 $tracked=in_array($d['type_key'],\App\Services\Dossiers\Ai\DossierAiSchema::EXPIRY_TYPES,true);
 if(!$tracked)$precision='not_tracked';
@endphp
<div class="box" id="proposal-{{ $proposal->id }}"><h3>{{ $d['title'] }} · trang {{ implode(',',$d['pages']) }}</h3>
<p>Chủ thể AI đọc: {{ $value('person_name') ?: $d['subject'] }}. {{ $d['scope_text'] }}</p>
@if(($d['replacement_candidates']??[]) && $source->machine_id)<p>Có {{ count($d['replacement_candidates']) }} chuỗi giấy cùng loại trên máy. <a href="{{ route('dossiers.machine',$source->machine_id) }}" target="_blank" rel="noopener">Đối chiếu giấy hiện có</a>, giữ đúng chủ thể/phạm vi phụ nếu thay giấy; chưa tự thay phiên bản.</p>@endif
@if($d['driver_candidates']??[])<p>Tài xế trùng tên để đối chiếu: #{{ implode(', #',$d['driver_candidates']) }}. Chưa chọn/gán chủ thể.</p>@endif
@if($d['subject']==='machine' || $d['subject']==='assignment')
@php $comparison=app(\App\Services\Dossiers\Ai\DossierComparison::class)->compare($d,$source); @endphp
<table><tr><th>Trường</th><th>OCR</th><th>Web</th><th>Đối chiếu</th></tr>
@foreach($comparison['fields'] as $key=>$row)<tr><td>{{ $key }}</td><td>{{ $row['ocr'] ?? '—' }}</td><td>{{ $row['web'] ?? '—' }}</td><td>{{ ['match'=>'Khớp','mismatch'=>'Không khớp','missing'=>'Chưa đủ dữ liệu'][$row['state']] }}</td></tr>@endforeach
</table>@endif
@foreach($proposal->issues as $issue)<p class="warn">{{ $issue }}</p>@endforeach
<details><summary>Bằng chứng từng trường</summary>@foreach($f as $key=>$e)<p><a href="{{ route('dossiers.ai.image',[$aiRun,$e['page']]) }}" target="dossier-evidence">{{ $key }} · trang {{ $e['page'] }}</a>: {{ $e['value'] }} · “{{ $e['quote'] }}” @if(!empty($e['box']))(vùng {{ implode(',',$e['box']) }})@endif</p>@endforeach</details>
@if($d['insured'])<details><summary>Danh sách BIC/phạm vi — phải đối chiếu đúng người</summary>@foreach($d['insured'] as $person)<p><a href="{{ route('dossiers.ai.image',[$aiRun,$person['page']]) }}" target="dossier-evidence">{{ $person['name'] }}</a> — {{ $person['quote'] }}</p>@endforeach</details>@endif
@if($proposal->version_id)<p>Đã duyệt thành giấy #{{ $proposal->version_id }}. Sửa/thay qua phần Giấy tờ đã lưu, không áp lại AI.</p>
@elseif(empty($d['multiple_documents']))
<form class="ai-proposal-form" data-comparison="{{ route('dossiers.ai.compare',$proposal) }}" data-id="{{ $proposal->id }}" data-page="{{ route('dossiers.ai.image',[$aiRun,$d['pages'][0]]) }}" data-original-type="{{ $d['type_id'] }}" method="post" action="{{ route('dossiers.ai.review',$proposal) }}">@csrf
<input type="hidden" name="source_revision" value="{{ $source->revision }}">
@if($d['type_key']==='identity')
<fieldset><legend>Định danh tài xế từ CCCD</legend>
<label>Họ tên pháp lý <input name="person_name" required value="{{ $value('person_name') }}"></label>
<label>Số CCCD <input name="identity" required inputmode="numeric" autocomplete="off" value="{{ $value('identity') }}"></label>
<input type="hidden" name="verify_identity" value="1">
</fieldset>
@endif
<p><label><input class="bulk-pick" type="checkbox" disabled> Chọn mục này để duyệt nhiều mục</label></p>
<div class="fields">
<label>Loại giấy <select name="type_id" required><option value="">Chọn đúng loại</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected($t->id===($record?->type_id ?? $d['type_id'] ?? null))>{{ $t->name }}</option>@endforeach</select></label>
<label class="type-reviewed" hidden><input type="checkbox" name="type_checked" value="1"> Đã kiểm tra loại trước khi dùng hạn</label>
<label>Chủ thể <select name="subject" class="ai-subject"><option value="machine" @selected($d['subject']==='machine')>Máy/xe</option><option value="driver" @selected($d['subject']==='driver')>Tài xế</option><option value="assignment" @selected($d['subject']==='assignment')>Phân công</option></select></label>
<label>Chọn chủ thể đã đối chiếu <select name="subject_id" class="ai-subject-id" required><option value="">Chọn đúng chủ thể</option><option data-subject="machine" value="{{ $source->machine_id }}" @selected($d['subject']==='machine' && $source->machine_confirmed)>{{ $source->machine?->asset_code }}</option>@foreach($drivers as $driver)<option data-subject="driver" value="{{ $driver->id }}" @selected($record?->driver_id===$driver->id)>{{ $driver->name }} #{{ $driver->id }} @if($d['type_key']==='identity') · Zalo {{ $senderIdentities->filter(fn($ids)=>$ids->contains($driver->id))->keys()->implode(', ') ?: 'chưa liên kết' }} @endif</option>@endforeach @foreach($assignments as $a)<option data-subject="assignment" value="{{ $a->id }}">Phân công #{{ $a->id }} {{ $a->project?->name }}</option>@endforeach</select></label>
<div><p class="subject-comparison" aria-live="polite"></p><label>Thay phiên bản <select name="record_id" class="replacement-record"><option value="">Chọn nếu thay giấy hiện có</option></select></label></div>
<label>Phạm vi phụ (giữ đúng tên nếu thay giấy)<input name="instance" value="{{ $record?->instance ?? 'main' }}" required></label>
<label>Trang theo thứ tự<input name="page_numbers" value="{{ implode(',',$d['pages']) }}" required></label>
<input type="hidden" name="effective_at" value="{{ $effective }}">
@if($rule=$d['expiry_suggestion'])
<input type="hidden" name="{{ $rule['anchor'] }}" value="{{ $rule['anchor_value'] }}">
@endif
<label>Độ chính xác hạn<select name="expiry_precision">@foreach(['unknown'=>'Chưa đọc được hạn','day'=>'Có hạn theo ngày','minute'=>'Có hạn đến giờ/phút','month'=>'Có hạn theo tháng','permanent'=>'Không có hạn','not_tracked'=>'Chỉ phân loại'] as $k=>$label)<option value="{{ $k }}" @selected($precision===$k)>{{ $label }}</option>@endforeach</select></label>
<label>Hạn trên giấy<input name="expiry_value" value="{{ $expiry['value']??'' }}" placeholder="YYYY-MM-DD / YYYY-MM / YYYY-MM-DD HH:MM:SS"></label>
<label>Diễn giải tháng<select name="month_rule"><option value="">Chưa xác nhận</option><option value="end_of_month">Tôi xác nhận hết ngày cuối tháng</option></select></label>
@if($d['type_key']==='bic_personal')
<label>Trang danh sách BIC <input name="bic_page" type="number" min="1"></label>
<label>Dòng danh sách có họ tên và CCCD <textarea name="bic_quote" maxlength="2000"></textarea></label>
@endif
</div>
<details><summary>Xoay trang bản xử lý/xuất</summary>@foreach($d['pages'] as $pn)<label>Trang {{ $pn }} <select name="rotations[{{ $pn }}]">@foreach([0,90,180,270] as $angle)<option value="{{ $angle }}" @selected(($aiRun->pages->firstWhere('page',$pn)?->result['rotation']??0)===$angle)>{{ $angle }}°</option>@endforeach</select></label>@endforeach</details>
@if($rule=$d['expiry_suggestion'])<div class="box"><strong>Tính theo quy tắc — chờ xác nhận</strong><p>{{ $rule['value'] }} · {{ $rule['anchor'] }}={{ $rule['anchor_value'] }} · phiên bản {{ $rule['version'] }} · {{ $rule['scope'] }}</p><label><input type="checkbox" name="use_rule" value="1"> Dùng hạn tính thay vì nhập tay</label><label><input type="checkbox" name="rule_scope_confirmed" value="1"> Đủ căn cứ và đúng phạm vi quy tắc này</label></div>@else<p>Không có hạn tính hợp lệ: giữ hạn đọc được hoặc Chưa xác định; quy tắc chưa bật không được tự áp.</p>@endif
<label><input type="checkbox" name="shared_pages" value="1"> Dùng chung có chủ đích (cần xác nhận trên các giấy dùng chung)</label>
<label><input type="checkbox" name="acknowledge_stale" value="1"> Đã đối chiếu lại kết quả AI với sửa tay hiện tại</label>
<label><input type="checkbox" name="reviewed_evidence" value="1" required> Đã đối chiếu scan, loại giấy, các ngày, đúng người và phạm vi</label>
<input type="hidden" name="reason" value="Đối chiếu và xác nhận đề xuất hồ sơ">
<button name="confirmed" value="1">Xác nhận</button>
</form>
@endif</div>
@endforeach
<div class="box" style="position:sticky;bottom:0;background:Canvas"><button type="button" id="ai-bulk">Xác nhận 0 mục đã chọn</button><div id="ai-bulk-status" role="status"></div></div>
<details><summary>Đã xử lý</summary><ul>
@foreach($aiRun->proposals->where('generation',$aiRun->generation)->whereNotNull('version_id') as $done)
<li>{{ $done->data['title'] }} · giấy #{{ $done->version_id }}</li>
@endforeach
</ul></details>
</div>
</div>
@endif
@endif
@endif
</section>
<script>
function updateBulkCount() {
    const button = document.getElementById('ai-bulk');
    if (!button) return;
    const count = document.querySelectorAll('.bulk-pick:checked:not(:disabled)').length;
    button.textContent = 'Xác nhận ' + count + ' mục đã chọn';
    button.disabled = count === 0;
}
document.querySelectorAll('a[data-page]').forEach(link => link.addEventListener('click', () => {
    document.getElementById('dossier-evidence').src = link.dataset.page;
}));
if (sessionStorage.getItem('dossier-next') === location.pathname) {
    sessionStorage.removeItem('dossier-next');
    const next = document.querySelector('.ai-proposal-form[data-page]');
    if (next) {
        document.getElementById('dossier-evidence').src = next.dataset.page;
        next.scrollIntoView({block:'nearest'});
    }
}
updateBulkCount();
document.querySelectorAll('.ai-proposal-form').forEach(form => {
    const subject = form.querySelector('.ai-subject');
    const target = form.querySelector('.ai-subject-id');
    const comparison = form.querySelector('.subject-comparison');
    const records = form.querySelector('.replacement-record');
    const tick = form.querySelector('.bulk-pick');
    const kind = form.elements.type_id;
    let matched = false;
    let sequence = 0;
    function refreshPick() {
        const precision = form.elements.expiry_precision.value;
        const changed = kind.value !== form.dataset.originalType;
        form.querySelector('.type-reviewed').hidden = !changed;
        form.elements.type_checked.required = changed && precision !== 'unknown' && precision !== 'not_tracked';
        tick.disabled = changed || !matched || !form.elements.reviewed_evidence.checked || precision === 'unknown' || (['day','minute','month'].includes(precision) && !form.elements.expiry_value.value) || !form.checkValidity();
        if (tick.disabled) tick.checked = false;
        updateBulkCount();
    }
    form.addEventListener('input', refreshPick);
    form.addEventListener('change', refreshPick);
    form.addEventListener('submit', () => sessionStorage.setItem('dossier-next', location.pathname));
    async function compare() {
        const current = ++sequence;
        records.replaceChildren(new Option('Chọn nếu thay giấy hiện có', ''));
        comparison.textContent = '';
        matched = false;
        refreshPick();
        if (!target.value || !kind.value) return;
        const query = new URLSearchParams({subject:subject.value, subject_id:target.value, type_id:form.elements.type_id.value});
        try {
            const response = await fetch(form.dataset.comparison + '?' + query, {headers:{Accept:'application/json'}});
            if (!response.ok || current !== sequence) return;
            const result = await response.json();
            matched = result.bulk_match === true;
            refreshPick();
            comparison.textContent = result.label + ' · ' + Object.entries(result.fields).map(([key,row]) =>
                key + ': ' + (row.ocr ?? '—') + ' / ' + (row.web ?? '—') + ' (' + row.label + ')').join(' · ');
            for (const record of result.records) {
                const option = new Option('Phạm vi ' + record.instance + ' · phiên bản #' + record.version, record.id);
                option.dataset.instance = record.instance;
                records.append(option);
            }
        } catch { if (current === sequence) comparison.textContent = 'Chưa tải được đối chiếu.'; }
    }
    function filterSubjects() {
        for (const option of target.options) {
            if (!option.dataset.subject) continue;
            option.hidden = option.dataset.subject !== subject.value;
            option.disabled = option.hidden;
        }
        if (target.selectedOptions[0]?.disabled) target.value = '';
        compare();
    }
    subject.onchange = filterSubjects;
    target.onchange = compare;
    form.elements.type_id.onchange = compare;
    records.onchange = () => {
        if (records.value) form.elements.instance.value = records.selectedOptions[0].dataset.instance;
    };
    filterSubjects();
});
@if($aiRun)
document.getElementById('ai-bulk')?.addEventListener('click',async()=>{const status=document.getElementById('ai-bulk-status');const forms=[...document.querySelectorAll('.ai-proposal-form')].filter(f=>f.querySelector('.bulk-pick').checked);if(!forms.length){status.textContent='Chọn ít nhất một mục.';return;}if(forms.some(f=>!f.reportValidity()))return;const items=forms.map(f=>{const data=Object.fromEntries(new FormData(f));data.rotations={};for(const [k,v]of new FormData(f)){const m=k.match(/^rotations\[(\d+)\]$/);if(m)data.rotations[m[1]]=v;}data.confirmed=true;return{id:Number(f.dataset.id),data};});try{const r=await fetch(@json(route('dossiers.ai.bulk',$aiRun)),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},body:JSON.stringify({items})});const result=await r.json();if(r.ok){sessionStorage.setItem('dossier-next', location.pathname);if(result.skipped?.length){alert('Mục cần xử lý riêng: '+result.skipped.join(', '));}location.reload();}else status.textContent=result.skipped?.length?'Các mục cần xử lý riêng: '+result.skipped.join(', '):result.message||'Chưa lưu: kiểm tra thông tin từng mục.';}catch{status.textContent='Không nhận được phản hồi; tải lại kiểm tra trước khi thử lại.';}});
@endif
</script>
