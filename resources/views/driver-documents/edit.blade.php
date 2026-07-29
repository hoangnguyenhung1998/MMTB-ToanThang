@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;
        use Illuminate\Support\Str;
        $url = $document->file_path ? Storage::disk('public')->url($document->file_path) : null;
        $isImage = $document->file_path && Str::of($document->file_path)->lower()->endsWith(['.jpg', '.jpeg', '.png', '.webp', '.gif']);
        $oldIssuedDate = old('issued_date', $document->issued_date ? \Carbon\Carbon::parse($document->issued_date)->format('d/m/Y') : '');
        $oldValidityPeriod = old('validity_period', $document->validity_period ?? '');
    @endphp
    <div class="container-fluid">
        <h1 class="h4 mb-3">Cập nhật giấy tờ tài xế</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('driver-documents.update', [$driver, $document]) }}" enctype="multipart/form-data" class="card p-3">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Loại giấy tờ</label>
                    <input type="text" name="doc_type" class="form-control" value="{{ old('doc_type', $document->doc_type) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ngày cấp</label>
                    <input type="text" name="issued_date" id="issuedDate" class="form-control" placeholder="VD: 05/11/2025" value="{{ $oldIssuedDate }}">
                    <div class="form-text">Nhập dạng ngày/tháng/năm.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Thời hạn</label>
                    <select name="validity_period" id="validityPeriod" class="form-select">
                        <option value="">-- Chọn thời hạn --</option>
                        @foreach ($validityOptions as $value => $label)
                            <option value="{{ $value }}" @selected($oldValidityPeriod === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Ngày hết hạn sẽ được tự tính nếu có ngày cấp và thời hạn.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ngày hết hạn tự tính</label>
                    <input type="text" id="expiryPreview" class="form-control" value="" readonly>
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
                            <span class="text-muted">Chưa có file</span>
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
                <a class="btn btn-outline-secondary" href="{{ route('drivers.show', $driver) }}">Quay lại</a>
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

            if (period === 'permanent') {
                preview.value = 'Vĩnh viễn';
                return;
            }
            if (!issuedDate || !period) {
                preview.value = '';
                return;
            }

            const map = { '3_months': 3, '6_months': 6, '1_year': 12, '2_years': 24 };
            preview.value = map[period] ? formatDate(addMonthsNoOverflow(issuedDate, map[period])) : '';
        }

        document.getElementById('issuedDate').addEventListener('input', updateExpiryPreview);
        document.getElementById('validityPeriod').addEventListener('change', updateExpiryPreview);
        updateExpiryPreview();
    </script>
@endsection
