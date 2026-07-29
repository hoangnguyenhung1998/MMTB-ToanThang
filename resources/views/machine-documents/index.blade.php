@extends('layouts.app')

@section('content')
@php
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Str;
    use Carbon\Carbon;

    $today = Carbon::today();
    $pageDocuments = collect($documents->items());
    $expiredCount = 0;
    $soonCount = 0;
    $validCount = 0;
    $permanentCount = 0;

    foreach ($pageDocuments as $item) {
        if (!$item->expiry_date) {
            $permanentCount++;
            continue;
        }
        $daysLeft = $today->diffInDays(Carbon::parse($item->expiry_date)->startOfDay(), false);
        if ($daysLeft < 0) $expiredCount++;
        elseif ($daysLeft <= 30) $soonCount++;
        else $validCount++;
    }
@endphp

<div class="page-shell document-page-shell">
    <x-page-header
        eyebrow="Hồ sơ thiết bị"
        title="Giấy tờ xe · {{ $machine->asset_code }}"
        subtitle="Theo dõi hiệu lực, tệp đính kèm và các hồ sơ cần xử lý."
    >
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('machine-documents.create', $machine) }}">+ Thêm giấy tờ</a>
            <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Quay lại máy</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="document-summary-heading">
        <div>
            <h2>Tình trạng hồ sơ</h2>
            <p>Các nhóm trạng thái bên dưới được tính trên trang danh sách hiện tại.</p>
        </div>
        <span class="summary-total-chip">Tổng {{ method_exists($documents, 'total') ? $documents->total() : $documents->count() }} hồ sơ</span>
    </div>

    <div class="document-kpi-grid">
        <x-stat-card label="Đã hết hạn" :value="$expiredCount" hint="Cần xử lý ngay" icon="!" tone="danger" />
        <x-stat-card label="Sắp hết hạn" :value="$soonCount" hint="Trong vòng 30 ngày" icon="⏳" tone="warning" />
        <x-stat-card label="Còn hiệu lực" :value="$validCount" hint="Trên 30 ngày" icon="✓" tone="success" />
        <x-stat-card label="Không thời hạn" :value="$permanentCount" hint="Không có ngày hết hạn" icon="∞" tone="neutral" />
    </div>

    <div class="app-card document-list-card">
        <div class="document-list-header">
            <div>
                <h2>Danh sách giấy tờ</h2>
                <p>Ưu tiên xử lý các hồ sơ màu đỏ và vàng trước.</p>
            </div>
        </div>

        <div class="document-card-list">
            @forelse ($documents as $document)
                @php
                    $url = $document->file_path ? Storage::disk('public')->url($document->file_path) : null;
                    $isImage = $document->file_path && Str::of($document->file_path)->lower()->endsWith(['.jpg', '.jpeg', '.png', '.webp', '.gif']);
                    $statusClass = 'doc-permanent';
                    $statusLabel = 'Không thời hạn';
                    $daysText = 'Không có ngày hết hạn';

                    if ($document->expiry_date) {
                        $expiry = Carbon::parse($document->expiry_date)->startOfDay();
                        $daysLeft = $today->diffInDays($expiry, false);
                        if ($daysLeft < 0) {
                            $statusClass = 'doc-expired';
                            $statusLabel = 'Đã hết hạn';
                            $daysText = 'Quá hạn ' . abs($daysLeft) . ' ngày';
                        } elseif ($daysLeft <= 30) {
                            $statusClass = 'doc-soon';
                            $statusLabel = 'Sắp hết hạn';
                            $daysText = 'Còn ' . $daysLeft . ' ngày';
                        } else {
                            $statusClass = 'doc-valid';
                            $statusLabel = 'Còn hiệu lực';
                            $daysText = 'Còn ' . $daysLeft . ' ngày';
                        }
                    }
                @endphp

                <article class="document-item {{ $statusClass }}">
                    <div class="document-preview">
                        @if ($url && $isImage)
                            <img src="{{ $url }}" alt="{{ $document->doc_type }}">
                        @else
                            <div class="file-placeholder">
                                <span>{{ $url ? 'FILE' : '—' }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="document-content">
                        <div class="document-title-row">
                            <div>
                                <h3>{{ $document->doc_type }}</h3>
                                <span class="document-status-pill">{{ $statusLabel }}</span>
                            </div>
                            <div class="document-days">{{ $daysText }}</div>
                        </div>

                        <div class="document-meta-grid">
                            <div><span>Ngày cấp</span><strong>{{ $document->issued_date ? Carbon::parse($document->issued_date)->format('d/m/Y') : 'Chưa có' }}</strong></div>
                            <div><span>Ngày hết hạn</span><strong>{{ $document->expiry_date ? Carbon::parse($document->expiry_date)->format('d/m/Y') : 'Vĩnh viễn' }}</strong></div>
                            <div class="document-note"><span>Ghi chú</span><strong>{{ $document->note ?: 'Không có ghi chú' }}</strong></div>
                        </div>
                    </div>

                    <div class="document-actions">
                        <div class="file-actions">
                            @if (!$url)
                                <span class="file-missing-text">Chưa có tệp</span>
                            @elseif ($isImage)
                                <a class="btn btn-sm btn-outline-primary" href="{{ $url }}" target="_blank">Xem ảnh</a>
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $url }}" download>Tải ảnh</a>
                            @else
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $url }}" download>Tải tệp</a>
                            @endif
                        </div>
                        <div class="manage-actions">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('machine-documents.edit', [$machine, $document]) }}">Sửa</a>
                            <form method="POST" action="{{ route('machine-documents.delete', [$machine, $document]) }}" onsubmit="return confirm('Xoá giấy tờ này?')">
                                @csrf
                                <button class="btn btn-sm btn-outline-danger" type="submit">Xóa</button>
                            </form>
                        </div>
                    </div>
                </article>
            @empty
                <x-empty-state
                    icon="📄"
                    title="Chưa có giấy tờ"
                    description="Thêm hồ sơ đầu tiên để theo dõi tình trạng giấy tờ của thiết bị."
                >
                    <x-slot:action>
                        <a class="btn btn-primary" href="{{ route('machine-documents.create', $machine) }}">Thêm giấy tờ</a>
                    </x-slot:action>
                </x-empty-state>
            @endforelse
        </div>

        @if (method_exists($documents, 'links'))
            <div class="pagination-wrap">{{ $documents->links() }}</div>
        @endif
    </div>
</div>
@endsection
