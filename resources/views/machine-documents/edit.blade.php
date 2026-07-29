@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;
        use Illuminate\Support\Str;
        $url = $document->file_path ? Storage::disk('public')->url($document->file_path) : null;
        $isImage = $document->file_path && Str::of($document->file_path)->lower()->endsWith(['.jpg', '.jpeg', '.png', '.webp', '.gif']);
        $issuedValue = old('issued_date', $document->issued_date ? \Carbon\Carbon::parse($document->issued_date)->format('d/m/Y') : '');
        $validityValue = old('validity_period', $document->validity_period ?: ($document->expiry_date ? '' : 'permanent'));
    @endphp
    <div class="container-fluid">
        <h1 class="h4 mb-3">Cập nhật giấy tờ máy</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('machine-documents.update', [$machine, $document]) }}" enctype="multipart/form-data" class="card p-3">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Loại giấy tờ</label>
                    <select name="doc_type" class="form-select" required>
                        <option value="">-- Chọn loại --</option>
                        @foreach (['Đăng ký', 'Đăng kiểm', 'Kiểm định', 'Bảo hiểm xe/máy', 'Scan toàn bộ hồ sơ'] as $type)
                            <option value="{{ $type }}" @selected(old('doc_type', $document->doc_type) === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ngày cấp</label>
                    <input type="text" name="issued_date" id="issuedDate" class="form-control" placeholder="VD: 05/11/2025" value="{{ $issuedValue }}">
                    <div class="form-text">Nhập dạng ngày/tháng/năm.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Thời hạn</label>
                    <select name="validity_period" id="validityPeriod" class="form-select" required>
                        <option value="">-- Chọn thời hạn --</option>
                        @foreach ($validityOptions as $value => $label)
                            <option value="{{ $value }}" @selected($validityValue === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ngày hết hạn tự tính</label>
                    <input type="text" id="expiryPreview" class="form-control" value="" readonly>
                    @if ($document->expiry_date && !$document->validity_period)
                        <div class="form-text">Ngày hết hạn hiện tại: {{ \Carbon\Carbon::parse($document->expiry_date)->format('d/m/Y') }}. Chọn thời hạn để tính lại.</div>
                    @endif
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ghi chú</label>
                    <input type="text" name="note" class="form-control" value="{{ old('note', $document->note) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Thay file (nếu cần)</label>
                    <input type="file" name="file" class="form-control">
                    <div class="form-text">Tối đa 200MB/file.</div>
                    <div class="mt-2">
                        @if (!$url)
                            <span class="text-muted">Chưa có file đính kèm.</span>
                        @elseif ($isImage)
                            <img src="{{ $url }}" alt="preview" style="max-height: 80px;">
                        @else
                            <a href="{{ $url }}" download>Tải file hiện tại</a>
                        @endif
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary" type="submit">Cập nhật</button>
                <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Quay lại</a>
            </div>
        </form>
    </div>

    <script>
        function parseVietnameseDate(value) {
            const parts = value.trim().split(/[\/\-]/);
            if (parts.length !== 3) return null;
            const day = parseInt(parts[0], 10);
            const month = parseInt(parts[1], 10);
            const year = parseInt(parts[2], 10);
            if (!day || !month || !year) return null;
            const date = new Date(year, month - 1, day);
            if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;
            return date;
        }
        function formatDate(date) {
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            return `${day}/${month}/${date.getFullYear()}`;
        }
        function addMonthsNoOverflow(date, months) {
            const result = new Date(date.getTime());
            const targetMonth = result.getMonth() + months;
            const originalDay = result.getDate();
            result.setDate(1);
            result.setMonth(targetMonth);
            const lastDay = new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate();
            result.setDate(Math.min(originalDay, lastDay));
            return result;
        }
        function updateExpiryPreview() {
            const issuedDate = parseVietnameseDate(document.getElementById('issuedDate').value);
            const period = document.getElementById('validityPeriod').value;
            const preview = document.getElementById('expiryPreview');
            if (period === 'permanent') { preview.value = 'Vĩnh viễn'; return; }
            if (!issuedDate || !period) { preview.value = ''; return; }
            const map = { '3_months': 3, '6_months': 6, '1_year': 12, '2_years': 24 };
            preview.value = map[period] ? formatDate(addMonthsNoOverflow(issuedDate, map[period])) : '';
        }
        document.getElementById('issuedDate').addEventListener('input', updateExpiryPreview);
        document.getElementById('validityPeriod').addEventListener('change', updateExpiryPreview);
        updateExpiryPreview();
    </script>
@endsection
