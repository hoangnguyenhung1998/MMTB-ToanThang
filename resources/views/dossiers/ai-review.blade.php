<section class="d-panel" id="ocr-review">
<h2>Phân loại và đối chiếu hồ sơ</h2>
@if(!$aiAvailable)<p>Cần migration additive Phase18.1 trên database LOCAL đã xác minh và sao lưu trước khi dùng AI. Nhập tay Phase18 vẫn dùng được.</p>
@else
@unless(app(\App\Services\Dossiers\Ai\DossierVision::class)->probe()['configured'])<p><strong>Chưa cấu hình AI</strong> — chưa gọi API; nhập tay vẫn dùng được.</p>@endunless
@if($source->status==='ready' && !$aiRun)<form method="post" action="{{ route('dossiers.ai.request',$source) }}">@csrf<button>Phân loại bằng AI</button><button class="secondary" name="manual" value="1">Phân loại thủ công</button></form>@endif
@if($aiRun)
@php $aiLabels=['unconfigured'=>'Chưa cấu hình AI','queued'=>'Đang xếp hàng','processing'=>'Đang phân tích','ready'=>'Đã phân tích','failed'=>'Có lỗi cần kiểm tra']; @endphp
<p><strong>{{ $aiRun->paused ? 'Đã dừng xếp tiếp' : ($aiLabels[$aiRun->status] ?? $aiRun->status) }}</strong> · {{ $aiRun->pages->where('status','ready')->count() }}/{{ $aiRun->pages->count() }} trang · Run #{{ $aiRun->id }} · {{ $aiRun->pipeline }}</p>
<details><summary>Tùy chọn OCR</summary>
@if($source->status==='ready')<form method="post" action="{{ route('dossiers.ai.request',$source) }}">@csrf<button>Phân tích AI / kiểm tra cấu hình và xếp lại</button></form>@endif
<a href="{{ route('dossiers.ai.rules') }}">Quy tắc hạn nghiệp vụ</a>
@if($aiRun->status==='unconfigured')<p>Điền DOSSIERS_AI_ENDPOINT, DOSSIERS_AI_API_KEY, DOSSIERS_AI_MODEL trong dossier-worker/.env trên máy chạy worker hồ sơ; bật DOSSIERS_AI_ENABLED trên Laravel rồi bấm Phân tích AI. Chưa gọi API. Có thể nhập thủ công.</p>@else
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
</details>
@if($source->status==='ready')
@php
    $classified = $aiRun->proposals->where('generation',$aiRun->generation);
    $remaining = array_diff(range(1,$source->page_count),$classified->flatMap(fn($p) => $p->data['pages'])->all());
@endphp
@if($remaining)
<form class="box" method="post" action="{{ route('dossiers.ai.classify',$aiRun) }}">@csrf
<h3>Chưa phân loại</h3>
<a href="{{ route('dossiers.file',[$source,'source']) }}" target="_blank" rel="noopener">Xem PDF gốc</a>
@foreach($remaining as $number)
<label><input type="checkbox" name="pages[]" value="{{ $number }}"> Trang {{ $number }}</label>
<a href="{{ route('dossiers.file',[$source,'thumbnail',$number]) }}" target="_blank" rel="noopener">Xem trang</a>
@endforeach
<label>Loại giấy <select name="type_id" required><option value="">Chọn loại</option>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></label>
<button>Lưu phân loại</button>
</form>
@endif
<div class="d-review-grid">
<aside class="d-scan">
<div class="d-toolbar"><button type="button" class="secondary" data-page-step="-1" aria-label="Trang trước">‹</button>
<label>Trang <select id="dossier-page-select">@for($page=1;$page<=$source->page_count;$page++)<option value="{{ ($aiRun->pages->firstWhere('page',$page)?->image_object_id ? route('dossiers.ai.image',[$aiRun,$page]) : route('dossiers.file',[$source,'thumbnail',$page])) }}">{{ $page }} / {{ $source->page_count }}</option>@endfor</select></label>
<button type="button" class="secondary" data-page-step="1" aria-label="Trang sau">›</button></div>
<iframe title="Scan và bằng chứng" id="dossier-evidence" name="dossier-evidence" src="{{ $aiRun->pages->firstWhere('page',1)?->image_object_id ? route('dossiers.ai.image',[$aiRun,1]) : route('dossiers.file',[$source,'thumbnail',1]) }}"></iframe>
</aside>
<div>
<h3>Giấy đã phân loại</h3>
@forelse($aiRun->proposals->where('generation',$aiRun->generation)->whereNull('version_id') as $proposal)
@php
 $d=$proposal->data;$f=$d['fields'];$value=fn($key)=>$f[$key]['value']??'';
 $expiry=$f['expiry']??null;$precision=$expiry['precision']??($d['permanent_explicit']?'permanent':'unknown');
 $effective=app(\App\Services\Dossiers\DossierStatus::class)->now()->format('Y-m-d H:i:s');
 $record=$source->replacementVersion?->record;
 $suggestedDriver=$record?->driver_id ?? (($source->document_target['subject'] ?? null)==='driver' ? $source->document_target['id'] : app(\App\Services\Dossiers\Ai\DossierComparison::class)->suggestedDriver($d,$source,$drivers));
 $tracked=in_array($d['type_key'],\App\Services\Dossiers\Ai\DossierAiSchema::EXPIRY_TYPES,true);
 if(!$tracked)$precision='not_tracked';
@endphp
<div class="box" id="proposal-{{ $proposal->id }}"><h3><a href="{{ ($aiRun->pages->firstWhere('page',$d['pages'][0])?->image_object_id ? route('dossiers.ai.image',[$aiRun,$d['pages'][0]]) : route('dossiers.file',[$source,'thumbnail',$d['pages'][0]])) }}" target="dossier-evidence" data-page="{{ ($aiRun->pages->firstWhere('page',$d['pages'][0])?->image_object_id ? route('dossiers.ai.image',[$aiRun,$d['pages'][0]]) : route('dossiers.file',[$source,'thumbnail',$d['pages'][0]])) }}">{{ $d['title'] }} · trang {{ implode(',',$d['pages']) }}</a></h3>
<form method="post" action="{{ route('dossiers.ai.classify',$aiRun) }}">@csrf
<input type="hidden" name="proposal_id" value="{{ $proposal->id }}">
@foreach($d['pages'] as $number)<input type="hidden" name="pages[]" value="{{ $number }}">@endforeach
<label>Loại giấy <select name="type_id" required>@foreach($types as $t)<option value="{{ $t->id }}" @selected($d['type_id']===$t->id)>{{ $t->name }}</option>@endforeach</select></label>
<button>Đổi loại</button>
</form>
@if(app(\App\Services\Dossiers\Ai\DossierAiSchema::class)->informationFields($d['type_key']))
<form method="post" action="{{ route('dossiers.ai.information',$proposal) }}">@csrf<button>OCR thông tin</button></form>
@if(in_array('pending',$d['information_pages']??[],true))<p>Đang chờ OCR thông tin.</p>@endif
@if(in_array('failed',$d['information_pages']??[],true))<p class="warn">OCR thông tin lỗi ở một hoặc nhiều trang. Phân loại đã lưu. Có thể nhập tay.</p>@endif
@endif
<details><summary>Đối chiếu và bằng chứng từng trường</summary>
<p>Chủ thể AI đọc: {{ $value('person_name') ?: $d['subject'] }}. {{ $d['scope_text'] }}</p>
@if(($d['replacement_candidates']??[]) && $source->machine_id)<p>Có {{ count($d['replacement_candidates']) }} chuỗi giấy cùng loại trên máy. <a href="{{ route('dossiers.machine',$source->machine_id) }}" target="_blank" rel="noopener">Đối chiếu giấy hiện có</a>, giữ đúng chủ thể/phạm vi phụ nếu thay giấy; chưa tự thay phiên bản.</p>@endif
@if($d['driver_candidates']??[])<p>Tài xế trùng tên để đối chiếu: #{{ implode(', #',$d['driver_candidates']) }}. Chưa chọn/gán chủ thể.</p>@endif
@if($d['subject']==='machine' || $d['subject']==='assignment')
@php $comparison=app(\App\Services\Dossiers\Ai\DossierComparison::class)->compare($d,$source); @endphp
<table><tr><th>Trường</th><th>OCR</th><th>Web</th><th>Đối chiếu</th></tr>
@foreach($comparison['fields'] as $key=>$row)<tr><td>{{ $key }}</td><td>{{ $row['ocr'] ?? '—' }}</td><td>{{ $row['web'] ?? '—' }}</td><td>{{ ['match'=>'Khớp','mismatch'=>'Không khớp','missing'=>'Chưa đủ dữ liệu'][$row['state']] }}</td></tr>@endforeach
</table>@endif
@foreach($proposal->issues as $issue)<p class="warn">{{ $issue }}</p>@endforeach
<details><summary>Bằng chứng từng trường</summary>@foreach($f as $key=>$e)<p><a href="{{ ($aiRun->pages->firstWhere('page',$e['page'])?->image_object_id ? route('dossiers.ai.image',[$aiRun,$e['page']]) : route('dossiers.file',[$source,'thumbnail',$e['page']])) }}" target="dossier-evidence">{{ $key }} · trang {{ $e['page'] }}</a>: {{ $e['value'] }} · “{{ $e['quote'] }}” @if(!empty($e['box']))(vùng {{ implode(',',$e['box']) }})@endif</p>@endforeach</details>
@if($d['insured'])<details><summary>Danh sách BIC/phạm vi — phải đối chiếu đúng người</summary>@foreach($d['insured'] as $person)<p><a href="{{ route('dossiers.ai.image',[$aiRun,$person['page']]) }}" target="dossier-evidence">{{ $person['name'] }}</a> — {{ $person['quote'] }}</p>@endforeach</details>@endif
</details>
@if($proposal->version_id)<p>Đã duyệt thành giấy #{{ $proposal->version_id }}. Sửa/thay qua phần Giấy tờ đã lưu, không áp lại AI.</p>
@elseif(empty($d['multiple_documents']))
<form class="ai-proposal-form" data-comparison="{{ route('dossiers.ai.compare',$proposal) }}" data-id="{{ $proposal->id }}" data-page="{{ ($aiRun->pages->firstWhere('page',$d['pages'][0])?->image_object_id ? route('dossiers.ai.image',[$aiRun,$d['pages'][0]]) : route('dossiers.file',[$source,'thumbnail',$d['pages'][0]])) }}" data-original-type="{{ $d['type_id'] }}"
    data-original-pages="{{ implode(',',$d['pages']) }}" data-recorded-expiry="{{ $expiry['value'] ?? '' }}" data-expiry-precision="{{ $precision }}"
    data-source-ready="{{ (int) ($source->machine_confirmed && $source->revision === $aiRun->source_revision) }}" method="post" action="{{ route('dossiers.ai.review',$proposal) }}">@csrf
<input type="hidden" name="source_revision" value="{{ $source->revision }}">
@if($d['type_key']==='identity')
<fieldset><legend>Định danh tài xế từ CCCD</legend>
<label>Họ tên pháp lý <input name="person_name" required value="{{ $value('person_name') }}"></label>
<label>Số CCCD <input name="identity" required pattern="[0-9]{9}([0-9]{3})?" inputmode="numeric" autocomplete="off" value="{{ $value('identity') }}"></label>
<label>Ngày sinh <input type="date" name="birth_date" value="{{ $value('birth_date') }}"></label>
<input type="hidden" name="verify_identity" value="1">
</fieldset>
@endif
<p><label><input class="bulk-pick" type="checkbox" disabled> Chọn mục này để duyệt nhiều mục</label></p>
<div class="fields">
<label>Loại giấy <select name="type_id" required><option value="">Chọn đúng loại</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected($t->id===($record?->type_id ?? $d['type_id'] ?? null))>{{ $t->name }}</option>@endforeach</select></label>
<label class="type-reviewed" hidden><input type="checkbox" name="type_checked" value="1"> Đã kiểm tra loại trước khi dùng hạn</label>
<label>Chủ thể <select name="subject" class="ai-subject"><option value="machine" @selected(($source->document_target['subject'] ?? $d['subject'])==='machine')>Máy/xe</option><option value="driver" @selected(($source->document_target['subject'] ?? $d['subject'])==='driver')>Tài xế</option><option value="assignment" @selected($d['subject']==='assignment')>Phân công</option></select></label>
<label>Chọn chủ thể đã đối chiếu <select name="subject_id" class="ai-subject-id" required><option value="">Chọn đúng chủ thể</option><option data-subject="machine" value="{{ $source->machine_id }}" @selected($d['subject']==='machine' && $source->machine_confirmed)>{{ $source->machine?->asset_code }}</option>@foreach($drivers as $driver)<option data-subject="driver" value="{{ $driver->id }}" @selected($suggestedDriver===$driver->id)>{{ $driverLabels[$driver->id] ?? $driver->name }} #{{ $driver->id }} @if($d['type_key']==='identity') · Zalo {{ $senderIdentities->filter(fn($ids)=>$ids->contains($driver->id))->keys()->implode(', ') ?: 'chưa liên kết' }} @endif</option>@endforeach @foreach($assignments as $a)<option data-subject="assignment" value="{{ $a->id }}">Phân công #{{ $a->id }} {{ $a->project?->name }}</option>@endforeach</select></label>
<div><p class="subject-comparison" aria-live="polite"></p><label>Thay phiên bản <select name="record_id" class="replacement-record"><option value="">Chọn nếu thay giấy hiện có</option></select></label></div>
<label>Phạm vi phụ (giữ đúng tên nếu thay giấy)<input name="instance" value="{{ $record?->instance ?? 'main' }}" required></label>
<label>Trang theo thứ tự<input name="page_numbers" value="{{ implode(',',$d['pages']) }}" required></label>
<input type="hidden" name="effective_at" value="{{ $effective }}">
@if($rule=$d['expiry_suggestion'])
<input type="hidden" name="{{ $rule['anchor'] }}" value="{{ $rule['anchor_value'] }}">
@endif
@if($d['type_key']!=='identity')
<label>Độ chính xác hạn<select name="expiry_precision">@foreach(['unknown'=>'Chưa đọc được hạn','day'=>'Có hạn theo ngày','minute'=>'Có hạn đến giờ/phút','month'=>'Có hạn theo tháng','permanent'=>'Không có hạn','not_tracked'=>'Chỉ phân loại'] as $k=>$label)<option value="{{ $k }}" @selected($precision===$k)>{{ $label }}</option>@endforeach</select></label>
<label>Hạn trên giấy<input name="expiry_value" value="{{ $expiry['value']??'' }}" placeholder="YYYY-MM-DD / YYYY-MM / YYYY-MM-DD HH:MM:SS"></label>
<label>Diễn giải tháng<select name="month_rule"><option value="">Chưa xác nhận</option><option value="end_of_month">Tôi xác nhận hết ngày cuối tháng</option></select></label>
@else<input type="hidden" name="expiry_precision" value="not_tracked"><input type="hidden" name="expiry_value" value="">@endif
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
@empty<p>Không còn mục chờ xác nhận.</p>@endforelse
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
const pagePicker = document.getElementById('dossier-page-select');
function showEvidence(url) {
    if (!pagePicker) return;
    const option = [...pagePicker.options].find(item => item.value === url);
    if (option) pagePicker.value = url;
    document.getElementById('dossier-evidence').src = url;
}
pagePicker?.addEventListener('change', () => showEvidence(pagePicker.value));
document.querySelectorAll('[data-page-step]').forEach(button => button.addEventListener('click', () => {
    pagePicker.selectedIndex = Math.max(0, Math.min(pagePicker.length - 1, pagePicker.selectedIndex + Number(button.dataset.pageStep)));
    showEvidence(pagePicker.value);
}));
document.querySelectorAll('a[target="dossier-evidence"]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    showEvidence(link.href);
}));
if (sessionStorage.getItem('dossier-next') === location.pathname) {
    sessionStorage.removeItem('dossier-next');
    const next = document.querySelector('.ai-proposal-form[data-page]');
    if (next) {
        showEvidence(next.dataset.page);
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
        const expiry = form.elements.expiry_value.value;
        const changed = kind.value !== form.dataset.originalType;
        const checkedRule = form.elements.use_rule?.checked && form.elements.rule_scope_confirmed?.checked;
        const sameExpiry = precision !== 'unknown' && precision === form.dataset.expiryPrecision && expiry === form.dataset.recordedExpiry;
        const samePages = form.elements.page_numbers.value.trim().split(/[\s,;]+/).join(',') === form.dataset.originalPages;
        form.querySelector('.type-reviewed').hidden = !changed;
        form.elements.type_checked.required = changed && precision !== 'unknown' && precision !== 'not_tracked';
        tick.disabled = changed || form.dataset.sourceReady !== '1' || !matched || !samePages || !(sameExpiry || checkedRule) || !form.elements.reviewed_evidence.checked || !form.checkValidity();
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
