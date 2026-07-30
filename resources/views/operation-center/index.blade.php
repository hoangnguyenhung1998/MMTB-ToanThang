@extends('layouts.app')

@section('content')
@php
    $statusLabels = [
        'WAIT_HANDOVER' => 'Chờ bàn giao',
        'HANDED_OVER' => 'Đã bàn giao',
        'ACTIVE' => 'Đang hoạt động',
        'RETURNED' => 'Đã trả',
    ];
@endphp

<div class="container-fluid operation-center-page">
    <div class="operation-center-header">
        <div>
            <div class="operation-center-eyebrow">TRUNG TÂM VẬN HÀNH</div>
            <h1>Việc cần xử lý</h1>
            <p>Tổng hợp tự động từ dữ liệu máy, tài xế và hồ sơ hiện tại.</p>
        </div>

        <div class="operation-center-header-actions">
            <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">Dashboard</a>
            <a href="{{ route('machines.index') }}" class="btn btn-primary">Danh sách máy</a>
        </div>
    </div>

    <div class="operation-summary-grid">
        @php
            $summaryCards = [
                ['anchor' => 'returned-not-synced', 'label' => 'Chưa đẩy app trả', 'value' => $counts['returned_not_synced'], 'tone' => 'danger', 'icon' => '↙'],
                ['anchor' => 'waiting-handover', 'label' => 'Chờ bàn giao', 'value' => $counts['waiting_handover'], 'tone' => 'warning', 'icon' => '⌛'],
                ['anchor' => 'missing-gps', 'label' => 'Thiếu file GPS', 'value' => $counts['missing_gps'], 'tone' => 'orange', 'icon' => '⌖'],
                ['anchor' => 'missing-driver', 'label' => 'Chưa có tài xế', 'value' => $counts['missing_driver'], 'tone' => 'primary', 'icon' => '♙'],
                ['anchor' => 'expiry-documents', 'label' => 'Hồ sơ hết hạn', 'value' => $counts['expired_documents'], 'tone' => 'danger-soft', 'icon' => '!'],
                ['anchor' => 'expiry-documents', 'label' => 'Hồ sơ sắp hết hạn', 'value' => $counts['expiring_documents'], 'tone' => 'info', 'icon' => '◷'],
            ];
        @endphp

        @foreach ($summaryCards as $card)
            <a href="#{{ $card['anchor'] }}" class="operation-summary-card is-{{ $card['tone'] }}">
                <span class="operation-summary-icon">{{ $card['icon'] }}</span>
                <span class="operation-summary-copy">
                    <small>{{ $card['label'] }}</small>
                    <strong>{{ number_format($card['value']) }}</strong>
                </span>
            </a>
        @endforeach
    </div>

    <div class="operation-center-note">
        <span class="operation-center-note-icon">i</span>
        <div>
            <strong>Tổng cộng {{ number_format($counts['total']) }} đầu việc</strong>
            <p>Mỗi nhóm hiển thị tối đa 20 mục ưu tiên. Bấm mã máy để mở trang chi tiết.</p>
        </div>
    </div>

    <section class="operation-section" id="returned-not-synced">
        <div class="operation-section-head">
            <div>
                <span class="operation-section-kicker is-danger">Ưu tiên cao</span>
                <h2>Máy chưa đẩy app trả</h2>
                <p>Máy đã trả nhưng chưa được xác nhận hoàn tất trên ứng dụng.</p>
            </div>
            <span class="operation-section-count">{{ number_format($counts['returned_not_synced']) }}</span>
        </div>

        <div class="operation-task-list">
            @forelse ($returnedNotSynced as $machine)
                <article class="operation-task-card">
                    <div class="operation-task-symbol is-danger">↙</div>
                    <div class="operation-task-main">
                        <a href="{{ route('machines.show', $machine) }}" class="operation-task-title">{{ $machine->asset_code }}</a>
                        <div class="operation-task-meta">
                            <span>{{ $machine->machine_type ?: 'Chưa có loại máy' }}</span>
                            <span>{{ $machine->company ?: '-' }}</span>
                            <span class="operation-status-badge is-returned">Đã trả</span>
                        </div>
                    </div>
                    <div class="operation-task-actions">
                        <a href="{{ route('machines.show', $machine) }}" class="btn btn-sm btn-outline-secondary">Chi tiết</a>
                        <form method="POST" action="{{ route('ops.return-app.mark', $machine) }}"
                              onsubmit="return confirm('Xác nhận máy này đã được trả trên app?')">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-danger">Đã đẩy app</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="operation-empty"><strong>Không có máy chờ đồng bộ app</strong><span>Nhóm công việc này đã được xử lý hết.</span></div>
            @endforelse
        </div>
    </section>

    <section class="operation-section" id="waiting-handover">
        <div class="operation-section-head">
            <div>
                <span class="operation-section-kicker is-warning">Nghiệp vụ</span>
                <h2>Máy chờ bàn giao</h2>
                <p>Thiết bị chưa hoàn tất quy trình hoặc biên bản bàn giao.</p>
            </div>
            <span class="operation-section-count">{{ number_format($counts['waiting_handover']) }}</span>
        </div>

        <div class="operation-task-list">
            @forelse ($waitingHandover as $machine)
                <article class="operation-task-card">
                    <div class="operation-task-symbol is-warning">⌛</div>
                    <div class="operation-task-main">
                        <a href="{{ route('machines.show', $machine) }}" class="operation-task-title">{{ $machine->asset_code }}</a>
                        <div class="operation-task-meta">
                            <span>{{ $machine->machine_type ?: 'Chưa có loại máy' }}</span>
                            <span>{{ $machine->company ?: '-' }}</span>
                            <span class="operation-status-badge is-waiting">Chờ bàn giao</span>
                        </div>
                    </div>
                    <div class="operation-task-actions">
                        <a href="{{ route('machines.show', $machine) }}" class="btn btn-sm btn-outline-secondary">Chi tiết</a>
                        <a href="{{ route('ops.handover.form', $machine) }}" class="btn btn-sm btn-warning">Bàn giao</a>
                    </div>
                </article>
            @empty
                <div class="operation-empty"><strong>Không có máy chờ bàn giao</strong><span>Nhóm công việc này đã được xử lý hết.</span></div>
            @endforelse
        </div>
    </section>

    <section class="operation-section" id="missing-gps">
        <div class="operation-section-head">
            <div>
                <span class="operation-section-kicker is-orange">Dữ liệu thiếu</span>
                <h2>Thiết bị thiếu file GPS</h2>
                <p>Máy chưa được đánh dấu đã bổ sung file GPS.</p>
            </div>
            <span class="operation-section-count">{{ number_format($counts['missing_gps']) }}</span>
        </div>

        <div class="operation-task-list">
            @forelse ($missingGps as $machine)
                <article class="operation-task-card">
                    <div class="operation-task-symbol is-orange">⌖</div>
                    <div class="operation-task-main">
                        <a href="{{ route('machines.show', $machine) }}" class="operation-task-title">{{ $machine->asset_code }}</a>
                        <div class="operation-task-meta">
                            <span>{{ $machine->machine_type ?: 'Chưa có loại máy' }}</span>
                            <span>{{ $machine->plate_no ?: 'Chưa có biển số' }}</span>
                            <span>{{ $statusLabels[$machine->status] ?? $machine->status }}</span>
                        </div>
                    </div>
                    <div class="operation-task-actions">
                        <a href="{{ route('machines.show', $machine) }}" class="btn btn-sm btn-outline-secondary">Kiểm tra</a>
                        <a href="{{ route('machines.edit', $machine) }}" class="btn btn-sm btn-outline-warning">Cập nhật máy</a>
                    </div>
                </article>
            @empty
                <div class="operation-empty"><strong>Không có thiết bị thiếu GPS</strong><span>Dữ liệu GPS hiện đã đầy đủ.</span></div>
            @endforelse
        </div>
    </section>

    <section class="operation-section" id="missing-driver">
        <div class="operation-section-head">
            <div>
                <span class="operation-section-kicker is-primary">Nhân sự vận hành</span>
                <h2>Máy chưa có tài xế</h2>
                <p>Thiết bị đã bàn giao hoặc đang hoạt động nhưng chưa gán tài xế hiện tại.</p>
            </div>
            <span class="operation-section-count">{{ number_format($counts['missing_driver']) }}</span>
        </div>

        <div class="operation-task-list">
            @forelse ($missingDriver as $machine)
                <article class="operation-task-card">
                    <div class="operation-task-symbol is-primary">♙</div>
                    <div class="operation-task-main">
                        <a href="{{ route('machines.show', $machine) }}" class="operation-task-title">{{ $machine->asset_code }}</a>
                        <div class="operation-task-meta">
                            <span>{{ $machine->machine_type ?: 'Chưa có loại máy' }}</span>
                            <span>{{ $machine->company ?: '-' }}</span>
                            <span>{{ $statusLabels[$machine->status] ?? $machine->status }}</span>
                        </div>
                    </div>
                    <div class="operation-task-actions">
                        <a href="{{ route('machines.show', $machine) }}" class="btn btn-sm btn-outline-secondary">Chi tiết</a>
                        <a href="{{ route('ops.assign-driver.form', $machine) }}" class="btn btn-sm btn-primary">Gán tài xế</a>
                    </div>
                </article>
            @empty
                <div class="operation-empty"><strong>Không có máy thiếu tài xế</strong><span>Các máy đang vận hành đều đã có tài xế.</span></div>
            @endforelse
        </div>
    </section>

    <section class="operation-section" id="expiry-documents">
        <div class="operation-section-head">
            <div>
                <span class="operation-section-kicker is-danger">Hồ sơ</span>
                <h2>Giấy tờ cần xử lý</h2>
                <p>Hồ sơ đã hết hạn hoặc sẽ hết hạn trong vòng 30 ngày.</p>
            </div>
            <a href="{{ route('expiries.index') }}" class="btn btn-sm btn-outline-secondary">Xem tất cả</a>
        </div>

        <div class="operation-task-list">
            @forelse ($expiryItems as $item)
                @php
                    $isExpired = $item['days_diff'] < 0;
                    $detailUrl = $item['owner_type'] === 'machine'
                        ? route('machine-documents.index', $item['owner_id'])
                        : route('drivers.show', $item['owner_id']);
                @endphp

                <article class="operation-task-card">
                    <div class="operation-task-symbol {{ $isExpired ? 'is-danger' : 'is-warning' }}">{{ $isExpired ? '!' : '◷' }}</div>
                    <div class="operation-task-main">
                        <a href="{{ $detailUrl }}" class="operation-task-title">{{ $item['owner_label'] }}</a>
                        <div class="operation-task-meta">
                            <span>{{ $item['owner_type'] === 'machine' ? 'Thiết bị' : 'Tài xế' }}</span>
                            <span>{{ $item['doc_type'] }}</span>
                            <span>Hết hạn: {{ $item['expiry_date']->format('d/m/Y') }}</span>
                        </div>
                    </div>
                    <div class="operation-task-expiry {{ $isExpired ? 'is-expired' : 'is-expiring' }}">
                        {{ $isExpired ? 'Quá hạn '.abs($item['days_diff']).' ngày' : 'Còn '.$item['days_diff'].' ngày' }}
                    </div>
                    <div class="operation-task-actions">
                        <a href="{{ $detailUrl }}" class="btn btn-sm {{ $isExpired ? 'btn-danger' : 'btn-outline-warning' }}">Xử lý hồ sơ</a>
                    </div>
                </article>
            @empty
                <div class="operation-empty"><strong>Không có giấy tờ cần xử lý</strong><span>Chưa có hồ sơ hết hạn hoặc sắp hết hạn trong 30 ngày.</span></div>
            @endforelse
        </div>
    </section>
</div>

<style>
.operation-center-page{padding-bottom:32px}.operation-center-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:22px}.operation-center-eyebrow{margin-bottom:6px;color:#64748b;font-size:12px;font-weight:800;letter-spacing:.08em}.operation-center-header h1{margin:0;color:#0f172a;font-size:30px;font-weight:800;letter-spacing:-.03em}.operation-center-header p{margin:8px 0 0;color:#64748b;font-size:14px}.operation-center-header-actions{display:flex;gap:10px}.operation-summary-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.operation-summary-card{display:flex;align-items:center;gap:12px;min-height:96px;padding:15px;border:1px solid #e2e8f0;border-top:4px solid #64748b;border-radius:16px;background:#fff;color:#0f172a;text-decoration:none;box-shadow:0 8px 24px rgba(15,23,42,.04)}.operation-summary-card:hover{color:#0f172a;transform:translateY(-1px)}.operation-summary-card.is-warning{border-top-color:#d6a227}.operation-summary-card.is-danger,.operation-summary-card.is-danger-soft{border-top-color:#dc3545}.operation-summary-card.is-orange{border-top-color:#ea7b24}.operation-summary-card.is-primary{border-top-color:#4077e8}.operation-summary-card.is-info{border-top-color:#0ea5e9}.operation-summary-icon{display:inline-flex;width:40px;height:40px;align-items:center;justify-content:center;border-radius:12px;background:#f1f5f9;font-size:18px;font-weight:800}.operation-summary-copy{display:flex;min-width:0;flex-direction:column}.operation-summary-copy small{color:#64748b;font-size:12px;font-weight:700}.operation-summary-copy strong{margin-top:2px;font-size:27px;line-height:1}.operation-center-note{display:flex;gap:12px;margin-top:16px;padding:14px 16px;border:1px solid #bfdbfe;border-radius:14px;background:#eff6ff}.operation-center-note-icon{display:inline-flex;width:30px;height:30px;align-items:center;justify-content:center;border-radius:999px;background:#2563eb;color:#fff;font-weight:800}.operation-center-note strong{font-size:14px}.operation-center-note p{margin:3px 0 0;color:#64748b;font-size:12px}.operation-section{margin-top:22px;padding:22px;border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.04);scroll-margin-top:20px}.operation-section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.operation-section-head h2{margin:5px 0 0;color:#0f172a;font-size:19px;font-weight:800}.operation-section-head p{margin:5px 0 0;color:#64748b;font-size:13px}.operation-section-kicker{font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}.operation-section-kicker.is-danger{color:#dc3545}.operation-section-kicker.is-warning{color:#a16207}.operation-section-kicker.is-orange{color:#c2410c}.operation-section-kicker.is-primary{color:#2563eb}.operation-section-count{display:inline-flex;min-width:44px;height:36px;align-items:center;justify-content:center;padding:0 12px;border-radius:999px;background:#f1f5f9;font-size:16px;font-weight:800}.operation-task-list{display:flex;flex-direction:column;gap:9px}.operation-task-card{display:grid;grid-template-columns:42px minmax(0,1fr) auto auto;align-items:center;gap:13px;padding:13px 14px;border:1px solid #e2e8f0;border-radius:14px;background:#fff}.operation-task-card:hover{background:#f8fafc}.operation-task-symbol{display:inline-flex;width:40px;height:40px;align-items:center;justify-content:center;border-radius:12px;background:#f1f5f9;font-weight:800}.operation-task-symbol.is-danger{background:#fee2e2;color:#b91c1c}.operation-task-symbol.is-warning{background:#fef3c7;color:#a16207}.operation-task-symbol.is-orange{background:#ffedd5;color:#c2410c}.operation-task-symbol.is-primary{background:#dbeafe;color:#1d4ed8}.operation-task-main{display:flex;min-width:0;flex-direction:column}.operation-task-title{color:#0f172a;font-size:15px;font-weight:800;text-decoration:none}.operation-task-meta{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:4px;color:#64748b;font-size:12px}.operation-status-badge{padding:2px 7px;border-radius:999px;font-weight:700}.operation-status-badge.is-returned{background:#e2e8f0;color:#475569}.operation-status-badge.is-waiting{background:#fef3c7;color:#a16207}.operation-task-actions{display:flex;align-items:center;gap:8px}.operation-task-actions form{margin:0}.operation-task-expiry{font-size:12px;font-weight:800;white-space:nowrap}.operation-task-expiry.is-expired{color:#dc3545}.operation-task-expiry.is-expiring{color:#a16207}.operation-empty{display:flex;min-height:110px;flex-direction:column;align-items:center;justify-content:center;padding:20px;border:1px dashed #cbd5e1;border-radius:14px;color:#64748b;text-align:center}.operation-empty strong{color:#334155;font-size:14px}.operation-empty span{margin-top:5px;font-size:12px}@media(max-width:1399.98px){.operation-summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:991.98px){.operation-center-header{flex-direction:column}.operation-task-card{grid-template-columns:42px minmax(0,1fr)}.operation-task-actions,.operation-task-expiry{grid-column:2}}@media(max-width:575.98px){.operation-center-header h1{font-size:25px}.operation-center-header-actions{width:100%}.operation-center-header-actions .btn{flex:1}.operation-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.operation-summary-card{min-height:86px;padding:12px}.operation-summary-icon{display:none}.operation-section{padding:16px}.operation-task-actions{flex-wrap:wrap}.operation-task-actions .btn,.operation-task-actions form,.operation-task-actions form button{width:100%}}
</style>
@endsection
