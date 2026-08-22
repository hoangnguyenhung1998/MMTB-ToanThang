<section class="app-card journal-editor-card">
    <div class="ocr-card-head">
        <div><strong>Chỉnh sửa dòng nhật trình</strong><span>{{ $document->rows->count() }} dòng OCR</span></div>
        <button class="btn btn-sm btn-outline-primary" type="button" id="addJournalRow">Thêm dòng</button>
    </div>

    <form method="POST" action="{{ route('ocr-reviews.journal.update', $job) }}" id="journalReviewForm">
        @csrf
        @method('PUT')

        <div class="journal-document-fields">
            <label>
                <span>Mã máy xác nhận</span>
                <select name="machine_id" required>
                    <option value="">Chọn thiết bị</option>
                    @foreach ($machines as $machine)
                        <option value="{{ $machine->id }}" @selected((int) old('machine_id', $document->machine_id) === $machine->id)>{{ $machine->asset_code }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Ghi chú hậu kiểm</span>
                <input name="review_notes" value="{{ old('review_notes', $job->review_notes) }}" placeholder="Ghi chú chung">
            </label>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <div class="table-scroll">
            <table class="table table-modern journal-edit-table">
                <thead>
                <tr><th>STT</th><th>Ngày</th><th>Bắt đầu</th><th>Kết thúc</th><th>Phút</th><th>Nội dung công việc</th><th>Khối lượng</th><th>ĐVT</th><th>Vị trí</th><th>Người vận hành</th><th>Xóa</th></tr>
                </thead>
                <tbody id="journalRows">
                @foreach ($document->rows as $index => $row)
                    <tr class="{{ !empty($row->exceptions) ? 'journal-row-alert' : '' }}">
                        <td class="row-number">
                            {{ $loop->iteration }}
                            <input type="hidden" name="rows[{{ $index }}][id]" value="{{ $row->id }}">
                            <input type="hidden" name="rows[{{ $index }}][confidence]" value="{{ $row->confidence }}">
                            @foreach ($row->exceptions ?? [] as $exception)
                                <span class="journal-row-exception">{{ $labelException($exception) }}</span>
                            @endforeach
                        </td>
                        <td><input type="date" name="rows[{{ $index }}][work_date]" value="{{ old("rows.$index.work_date", $row->work_date?->format('Y-m-d')) }}"></td>
                        <td><input class="row-start" type="time" name="rows[{{ $index }}][start_time]" value="{{ old("rows.$index.start_time", $row->start_time ? substr($row->start_time, 0, 5) : '') }}"></td>
                        <td><input class="row-end" type="time" name="rows[{{ $index }}][end_time]" value="{{ old("rows.$index.end_time", $row->end_time ? substr($row->end_time, 0, 5) : '') }}"></td>
                        <td><output class="row-minutes">{{ $row->total_minutes ?? '—' }}</output></td>
                        <td><textarea name="rows[{{ $index }}][work_content]" rows="2">{{ old("rows.$index.work_content", $row->work_content) }}</textarea></td>
                        <td><input type="number" min="0" step="0.01" name="rows[{{ $index }}][quantity]" value="{{ old("rows.$index.quantity", $row->quantity) }}"></td>
                        <td><input name="rows[{{ $index }}][unit]" value="{{ old("rows.$index.unit", $row->unit) }}"></td>
                        <td><textarea name="rows[{{ $index }}][work_location]" rows="2">{{ old("rows.$index.work_location", $row->work_location) }}</textarea></td>
                        <td><input name="rows[{{ $index }}][operator_name]" value="{{ old("rows.$index.operator_name", $row->operator_name) }}"></td>
                        <td><label class="delete-row"><input type="checkbox" name="rows[{{ $index }}][delete]" value="1"> Xóa</label></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="journal-review-actions">
            <button class="btn btn-outline-primary" name="action" value="save">Lưu bản nháp</button>
            <button class="btn btn-success" name="action" value="approve">Duyệt nhật trình</button>
            <button class="btn btn-danger" name="action" value="reject">Từ chối</button>
        </div>
    </form>
</section>

<template id="journalRowTemplate">
    <tr>
        <td class="row-number"></td>
        <td><input type="date" data-name="work_date"></td>
        <td><input class="row-start" type="time" data-name="start_time"></td>
        <td><input class="row-end" type="time" data-name="end_time"></td>
        <td><output class="row-minutes">—</output></td>
        <td><textarea data-name="work_content" rows="2"></textarea></td>
        <td><input type="number" min="0" step="0.01" data-name="quantity"></td>
        <td><input data-name="unit"></td>
        <td><textarea data-name="work_location" rows="2"></textarea></td>
        <td><input data-name="operator_name"></td>
        <td><label class="delete-row"><input type="checkbox" data-name="delete" value="1"> Xóa</label><input type="hidden" data-name="confidence" value="1"></td>
    </tr>
</template>

<style>
.journal-editor-card{margin-top:16px;overflow:hidden}.journal-document-fields{display:grid;grid-template-columns:minmax(220px,.45fr) 1fr;gap:12px;padding:14px}.journal-document-fields label span{display:block;margin-bottom:6px;color:#475569;font-size:11px;font-weight:800}.journal-document-fields select,.journal-document-fields input{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px}.journal-edit-table{min-width:1700px}.journal-edit-table input,.journal-edit-table textarea{width:100%;min-width:105px;padding:7px;border:1px solid #cbd5e1;border-radius:7px}.journal-edit-table textarea{min-width:220px}.journal-edit-table input[name$="[unit]"]{min-width:70px}.journal-row-alert{background:#fffaf0}.journal-row-exception{display:block;margin-top:4px;color:#a05200;font-size:9px;font-weight:800;white-space:normal}.delete-row{color:#b42332;font-size:11px;font-weight:700;white-space:nowrap}.journal-review-actions{display:flex;justify-content:flex-end;gap:9px;padding:14px;border-top:1px solid var(--border)}@media(max-width:700px){.journal-document-fields{grid-template-columns:1fr}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.getElementById('journalRows');
    const template = document.getElementById('journalRowTemplate');
    let nextIndex = tbody?.querySelectorAll('tr').length || 0;

    const renumber = () => tbody?.querySelectorAll('tr').forEach((row, index) => {
        const cell = row.querySelector('.row-number');
        if (cell) cell.childNodes[0].textContent = String(index + 1);
    });

    const calculateMinutes = row => {
        const start = row.querySelector('.row-start')?.value;
        const end = row.querySelector('.row-end')?.value;
        const output = row.querySelector('.row-minutes');
        if (!start || !end || !output) return;
        const [sh, sm] = start.split(':').map(Number);
        const [eh, em] = end.split(':').map(Number);
        const minutes = (eh * 60 + em) - (sh * 60 + sm);
        output.textContent = minutes >= 0 ? minutes : 'Sai giờ';
    };

    tbody?.addEventListener('input', event => {
        if (event.target.matches('.row-start,.row-end')) calculateMinutes(event.target.closest('tr'));
    });

    document.getElementById('addJournalRow')?.addEventListener('click', () => {
        const row = template.content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[data-name]').forEach(input => {
            input.name = `rows[${nextIndex}][${input.dataset.name}]`;
            input.removeAttribute('data-name');
        });
        tbody.appendChild(row);
        nextIndex++;
        renumber();
    });
});
</script>
