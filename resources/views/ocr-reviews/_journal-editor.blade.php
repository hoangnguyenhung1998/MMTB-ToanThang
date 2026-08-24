@php
    $formatDuration = static function (?int $minutes): string {
        if ($minutes === null) return '—';
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;
        if ($hours === 0) return $remainder.' phút';
        return $remainder === 0 ? $hours.' giờ' : $hours.' giờ '.$remainder.' phút';
    };
@endphp

<section class="app-card journal-editor-card">
    <div class="ocr-card-head">
        <div><strong>Chỉnh sửa dòng nhật trình</strong><span>{{ $document->rows->count() }} dòng OCR</span></div>
        <button class="btn btn-sm btn-outline-primary" type="button" id="addJournalRow">Thêm dòng</button>
    </div>
    <form method="POST" action="{{ route('ocr-reviews.journal.update', $job) }}" id="journalReviewForm">
        @csrf @method('PUT')
        <div class="journal-document-fields">
            <label><span>Mã máy xác nhận</span><select name="machine_id" required><option value="">Chọn thiết bị</option>@foreach ($machines as $machine)<option value="{{ $machine->id }}" @selected((int) old('machine_id', $document->machine_id) === $machine->id)>{{ $machine->asset_code }}</option>@endforeach</select></label>
            <label><span>Ghi chú hậu kiểm</span><input name="review_notes" value="{{ old('review_notes', $job->review_notes) }}" placeholder="Ghi chú chung"></label>
        </div>
        @if ($errors->any())<div class="alert alert-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="journal-rows" id="journalRows">
            @foreach ($document->rows as $index => $row)
                <article class="journal-row {{ !empty($row->exceptions) ? 'journal-row-alert' : '' }}">
                    <div class="journal-row-heading">
                        <strong>Dòng <span class="row-number">{{ $loop->iteration }}</span></strong>
                        <div>@foreach ($row->exceptions ?? [] as $exception)<span class="journal-row-exception">{{ $labelException($exception) }}</span>@endforeach<button class="remove-journal-row" type="button">Xóa dòng</button></div>
                    </div>
                    <input type="hidden" name="rows[{{ $index }}][id]" value="{{ $row->id }}">
                    <input type="hidden" name="rows[{{ $index }}][confidence]" value="{{ $row->confidence }}">
                    <input type="hidden" name="rows[{{ $index }}][quantity]" value="{{ old("rows.$index.quantity", $row->quantity) }}">
                    <input type="hidden" name="rows[{{ $index }}][unit]" value="{{ old("rows.$index.unit", $row->unit) }}">
                    <input type="hidden" name="rows[{{ $index }}][operator_name]" value="{{ old("rows.$index.operator_name", $row->operator_name) }}">
                    <div class="journal-row-grid">
                        <label><span>Ngày công</span>
                            <input class="journal-date-display" type="text" inputmode="numeric" maxlength="10" placeholder="dd/mm/yyyy" value="{{ $row->work_date?->format('d/m/Y') }}">
                            <input class="journal-date-iso" type="hidden" name="rows[{{ $index }}][work_date]" value="{{ old("rows.$index.work_date", $row->work_date?->format('Y-m-d')) }}">
                        </label>
                        <label><span>Bắt đầu</span><input class="row-time row-start" type="text" inputmode="numeric" maxlength="5" placeholder="HH:mm" name="rows[{{ $index }}][start_time]" value="{{ old("rows.$index.start_time", $row->start_time ? substr($row->start_time, 0, 5) : '') }}"></label>
                        <label><span>Kết thúc</span><input class="row-time row-end" type="text" inputmode="numeric" maxlength="5" placeholder="HH:mm" name="rows[{{ $index }}][end_time]" value="{{ old("rows.$index.end_time", $row->end_time ? substr($row->end_time, 0, 5) : '') }}"></label>
                        <div class="duration-field"><span>Tổng thời gian</span><output class="row-duration">{{ $formatDuration($row->total_minutes) }}</output></div>
                        <label class="work-content-field"><span>Nội dung công việc</span><textarea name="rows[{{ $index }}][work_content]" rows="2">{{ old("rows.$index.work_content", $row->work_content) }}</textarea></label>
                        <label class="explanation-field"><span>Lỗi giải trình</span><textarea name="rows[{{ $index }}][error_explanation]" rows="2" placeholder="Mưa, nghỉ, chờ việc, chờ dầu...">{{ old("rows.$index.error_explanation", $row->error_explanation) }}</textarea></label>
                        <label class="location-field"><span>Vị trí</span><textarea name="rows[{{ $index }}][work_location]" rows="2">{{ old("rows.$index.work_location", $row->work_location) }}</textarea></label>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="journal-review-actions"><button class="btn btn-outline-primary" name="action" value="save">Lưu bản nháp</button><button class="btn btn-success" name="action" value="approve">Duyệt nhật trình</button><button class="btn btn-danger" name="action" value="reject">Từ chối</button></div>
    </form>
</section>

<template id="journalRowTemplate">
    <article class="journal-row">
        <div class="journal-row-heading"><strong>Dòng <span class="row-number"></span></strong><button class="remove-journal-row" type="button">Xóa dòng</button></div>
        <input type="hidden" data-name="confidence" value="1"><input type="hidden" data-name="quantity"><input type="hidden" data-name="unit"><input type="hidden" data-name="operator_name">
        <div class="journal-row-grid">
            <label><span>Ngày công</span><input class="journal-date-display" type="text" inputmode="numeric" maxlength="10" placeholder="dd/mm/yyyy"><input class="journal-date-iso" type="hidden" data-name="work_date"></label>
            <label><span>Bắt đầu</span><input class="row-time row-start" type="text" inputmode="numeric" maxlength="5" placeholder="HH:mm" data-name="start_time"></label>
            <label><span>Kết thúc</span><input class="row-time row-end" type="text" inputmode="numeric" maxlength="5" placeholder="HH:mm" data-name="end_time"></label>
            <div class="duration-field"><span>Tổng thời gian</span><output class="row-duration">—</output></div>
            <label class="work-content-field"><span>Nội dung công việc</span><textarea data-name="work_content" rows="2"></textarea></label>
            <label class="explanation-field"><span>Lỗi giải trình</span><textarea data-name="error_explanation" rows="2" placeholder="Mưa, nghỉ, chờ việc, chờ dầu..."></textarea></label>
            <label class="location-field"><span>Vị trí</span><textarea data-name="work_location" rows="2"></textarea></label>
        </div>
    </article>
</template>

<style>
.journal-editor-card{overflow:hidden}.journal-document-fields{display:grid;grid-template-columns:minmax(190px,.45fr) 1fr;gap:12px;padding:14px}.journal-document-fields label span,.journal-row-grid label span,.duration-field>span{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.journal-document-fields select,.journal-document-fields input,.journal-row-grid input,.journal-row-grid textarea{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}.journal-rows{display:grid;gap:10px;padding:0 14px 14px}.journal-row{padding:12px;border:1px solid #dbe3ee;border-radius:12px;background:#fff}.journal-row-alert{border-color:#f4c46b;background:#fffaf0}.journal-row-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}.journal-row-heading>div{display:flex;align-items:center;gap:8px}.journal-row-exception{color:#a05200;font-size:10px;font-weight:800}.remove-journal-row{padding:5px 9px;border:1px solid #fecdd3;border-radius:7px;background:#fff;color:#b42332;font-size:11px;font-weight:800;cursor:pointer}.journal-row-grid{display:grid;grid-template-columns:1.05fr .72fr .72fr .85fr;gap:10px}.work-content-field{grid-column:span 2}.explanation-field{grid-column:span 1}.location-field{grid-column:span 1}.duration-field output{display:flex;min-height:39px;align-items:center;padding:9px;border-radius:8px;background:#eff6ff;color:#174ea6;font-size:13px;font-weight:800}.journal-review-actions{display:flex;justify-content:flex-end;gap:9px;padding:14px;border-top:1px solid var(--border)}@media(max-width:1180px){.journal-row-grid{grid-template-columns:repeat(2,1fr)}.work-content-field,.explanation-field,.location-field{grid-column:span 1}}@media(max-width:700px){.journal-document-fields,.journal-row-grid{grid-template-columns:1fr}.work-content-field,.explanation-field,.location-field{grid-column:auto}.journal-review-actions{flex-wrap:wrap}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const rows = document.getElementById('journalRows');
    const template = document.getElementById('journalRowTemplate');
    let nextIndex = rows?.querySelectorAll('.journal-row').length || 0;
    const renumber = () => rows?.querySelectorAll('.journal-row').forEach((row, index) => { const number = row.querySelector('.row-number'); if (number) number.textContent = String(index + 1); });
    const formatDuration = minutes => { const hours = Math.floor(minutes / 60); const remainder = minutes % 60; if (!hours) return `${remainder} phút`; return remainder ? `${hours} giờ ${remainder} phút` : `${hours} giờ`; };
    const calculateDuration = row => {
        const start = row.querySelector('.row-start')?.value; const end = row.querySelector('.row-end')?.value; const output = row.querySelector('.row-duration'); if (!output) return;
        if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(start || '') || !/^([01]\d|2[0-3]):[0-5]\d$/.test(end || '')) { output.textContent = '—'; return; }
        const [sh, sm] = start.split(':').map(Number); const [eh, em] = end.split(':').map(Number); let minutes = (eh * 60 + em) - (sh * 60 + sm); if (minutes < 0) minutes += 1440; output.textContent = formatDuration(minutes);
    };
    const syncDate = input => {
        input.value = input.value.replace(/[^0-9/]/g, '').slice(0, 10);
        const match = input.value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        const hidden = input.closest('label')?.querySelector('.journal-date-iso');
        if (hidden) hidden.value = match ? `${match[3]}-${match[2]}-${match[1]}` : '';
    };
    rows?.addEventListener('input', event => {
        if (event.target.matches('.row-time')) { event.target.value = event.target.value.replace(/[^0-9:]/g, '').slice(0, 5); calculateDuration(event.target.closest('.journal-row')); }
        if (event.target.matches('.journal-date-display')) syncDate(event.target);
    });
    rows?.addEventListener('click', event => { const button = event.target.closest('.remove-journal-row'); if (!button) return; button.closest('.journal-row')?.remove(); renumber(); });
    document.getElementById('addJournalRow')?.addEventListener('click', () => {
        const row = template.content.firstElementChild.cloneNode(true); row.querySelectorAll('[data-name]').forEach(input => { input.name = `rows[${nextIndex}][${input.dataset.name}]`; input.removeAttribute('data-name'); });
        const previousDate = rows.querySelector('.journal-row:last-child .journal-date-display')?.value;
        if (previousDate) { row.querySelector('.journal-date-display').value = previousDate; syncDate(row.querySelector('.journal-date-display')); }
        rows.appendChild(row); nextIndex++; renumber();
    });
});
</script>
