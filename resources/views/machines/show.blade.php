@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;
        use Illuminate\Support\Str;

        $statusMeta = match ($machine->status) {
            'WAIT_HANDOVER' => ['label' => 'Chờ bàn giao', 'class' => 'status-wait'],
            'HANDED_OVER' => ['label' => 'Đã bàn giao', 'class' => 'status-handover'],
            'ACTIVE' => ['label' => 'Đang hoạt động', 'class' => 'status-active'],
            'RETURNED' => ['label' => 'Đã trả', 'class' => 'status-returned'],
            default => ['label' => $machine->status ?? 'Chưa xác định', 'class' => 'status-wait'],
        };

        $eventLabels = [
            'HANDOVER' => 'Bàn giao',
            'ACTIVATE' => 'Kích hoạt',
            'TRANSFER' => 'Điều chuyển',
            'RETURN' => 'Trả máy',
            'ASSIGN_DRIVER' => 'Gán tài xế',
            'CHANGE_DRIVER' => 'Đổi tài xế',
        ];
    @endphp

    <div class="page-shell machine-detail-page">
        <div class="detail-hero app-card">
            <div class="detail-hero-main">
                <a class="detail-back-link" href="{{ route('machines.index') }}">
                    <svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg>
                    Danh sách máy
                </a>

                <div class="detail-title-row">
                    <div>
                        <div class="page-eyebrow">Hồ sơ thiết bị</div>
                        <h1 class="page-title">{{ $machine->asset_code }}</h1>
                        <p class="page-subtitle">
                            {{ $machine->machine_type ?? 'Chưa cập nhật loại máy' }}
                            <span class="detail-separator">•</span>
                            {{ $machine->company ?? 'Chưa cập nhật công ty' }}
                        </p>
                    </div>
                    <span class="status-badge {{ $statusMeta['class'] }} detail-status-badge">
                        {{ $statusMeta['label'] }}
                    </span>
                </div>
            </div>

            <div class="detail-hero-actions">
                <a class="btn btn-outline-primary" href="{{ route('machines.edit', $machine) }}">
                    <svg class="button-icon" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4 11.5-11.5Z"/></svg>
                    Sửa thông tin
                </a>
                <a class="btn btn-primary" href="{{ route('machine-documents.index', $machine) }}">
                    <svg class="button-icon" viewBox="0 0 24 24"><path d="M6 3h9l3 3v15H6V3Zm3 7h6m-6 4h6m-6 4h4"/></svg>
                    Quản lý giấy tờ
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success mt-3">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger mt-3">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="detail-alert-grid">
            @if ($machine->status === 'RETURNED' && !$machine->returned_to_app)
                <div class="detail-alert detail-alert-danger">
                    <div class="detail-alert-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.3 4.6 2.7 18a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.7 4.6a2 2 0 0 0-3.4 0Z"/></svg>
                    </div>
                    <div>
                        <strong>Chưa đẩy app trả</strong>
                        <span>Máy đã trả ngoài thực tế nhưng chưa được xác nhận trên app.</span>
                    </div>
                    <form method="POST" action="{{ route('ops.return-app.mark', $machine) }}" onsubmit="return confirm('Xác nhận máy này đã được trả trên app?')">
                        @csrf
                        <button class="btn btn-sm btn-danger" type="submit">Đánh dấu đã đẩy app</button>
                    </form>
                </div>
            @endif

            @if (!($currentInfo['gps_file_added'] ?? false))
                <div class="detail-alert detail-alert-warning">
                    <div class="detail-alert-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 21s7-4.4 7-11a7 7 0 1 0-14 0c0 6.6 7 11 7 11Zm0-8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/></svg>
                    </div>
                    <div>
                        <strong>Chưa thêm file GPS</strong>
                        <span>Hồ sơ GPS của thiết bị hiện chưa được đánh dấu đầy đủ.</span>
                    </div>
                </div>
            @endif
        </div>

        <section class="detail-section">
            <div class="section-heading-row">
                <div>
                    <div class="section-kicker">Tổng quan</div>
                    <h2 class="section-title">Thông tin hiện tại</h2>
                </div>
            </div>

            <div class="overview-grid">
                <article class="overview-card app-card overview-card-primary">
                    <div class="overview-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16v10H4V7Zm3-3h10v3H7V4Zm1 13v3m8-3v3M8 11h8"/></svg></div>
                    <span class="overview-label">Mã tài sản</span>
                    <strong class="overview-value">{{ $currentInfo['asset_code'] ?? $machine->asset_code }}</strong>
                </article>
                <article class="overview-card app-card">
                    <div class="overview-icon"><svg viewBox="0 0 24 24"><path d="M4 20V8l8-4 8 4v12H4Zm4-8h8m-8 4h8"/></svg></div>
                    <span class="overview-label">Dự án hiện tại</span>
                    <strong class="overview-value">{{ $currentInfo['current_project']['name'] ?? 'Chưa phân dự án' }}</strong>
                </article>
                <article class="overview-card app-card">
                    <div class="overview-icon"><svg viewBox="0 0 24 24"><path d="M3 20h18M5 20V9l7-5 7 5v11M9 20v-6h6v6"/></svg></div>
                    <span class="overview-label">Ban chỉ huy</span>
                    <strong class="overview-value">{{ $currentInfo['current_command_center']['name'] ?? 'Chưa có BCH' }}</strong>
                </article>
                <article class="overview-card app-card">
                    <div class="overview-icon"><svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 8a7 7 0 0 0-14 0"/></svg></div>
                    <span class="overview-label">Tài xế hiện tại</span>
                    <strong class="overview-value">{{ $currentInfo['current_driver']['name'] ?? 'Chưa gán tài xế' }}</strong>
                </article>
            </div>

            <div class="detail-info-card app-card">
                <div class="detail-info-grid">
                    <div class="detail-info-item">
                        <span>Loại máy</span>
                        <strong>{{ app(\App\Services\MachineSpecificationNormalizer::class)->displayType($machine) ?: '-' }}</strong>
                    </div>
                    <div class="detail-info-item">
                        <span>Nhãn hiệu / Model / Biển số</span>
                        <strong>{{ app(\App\Services\MachineSpecificationNormalizer::class)->displayIdentity($machine) ?: '-' }}</strong>
                    </div>
                    <div class="detail-info-item">
                        <span>Số khung</span>
                        <strong>{{ $machine->chassis_no ?? '-' }}</strong>
                    </div>
                    <div class="detail-info-item">
                        <span>Số máy</span>
                        <strong>{{ $machine->engine_no ?? '-' }}</strong>
                    </div>
                    <div class="detail-info-item">
                        <span>Năm sản xuất</span>
                        <strong>{{ $machine->manufacture_year ?? '-' }}</strong>
                    </div>
                    <div class="detail-info-item">
                        <span>Thời gian vào gần nhất</span>
                        <strong>{{ $currentInfo['last_time_in'] ?? '-' }}</strong>
                    </div>
                    <div class="detail-info-item">
                        <span>Thời gian ra gần nhất</span>
                        <strong>{{ $currentInfo['last_time_out'] ?? '-' }}</strong>
                    </div>
                    <div class="detail-info-item">
                        <span>Trạng thái GPS</span>
                        <strong class="{{ ($currentInfo['gps_file_added'] ?? false) ? 'text-success' : 'text-warning' }}">
                            {{ ($currentInfo['gps_file_added'] ?? false) ? 'Đã thêm file' : 'Chưa thêm file' }}
                        </strong>
                    </div>
                </div>
            </div>
        </section>

        <section class="detail-section">
            <div class="section-heading-row">
                <div>
                    <div class="section-kicker">Vận hành</div>
                    <h2 class="section-title">Thao tác nhanh</h2>
                    <p class="section-description">Các nghiệp vụ chính được nhóm theo luồng vận hành của thiết bị.</p>
                </div>
            </div>

            <div class="operation-grid">
                <a class="operation-card operation-blue" href="{{ route('ops.handover.form', $machine) }}">
                    <span class="operation-icon"><svg viewBox="0 0 24 24"><path d="M4 12h16M14 6l6 6-6 6M4 6h5v12H4"/></svg></span>
                    <span><strong>Bàn giao</strong><small>Đưa máy vào dự án/BCH</small></span>
                </a>

                <div class="operation-card operation-green operation-activate">
                    <span class="operation-icon"><svg viewBox="0 0 24 24"><path d="m8 5 11 7-11 7V5Z"/></svg></span>
                    <div class="operation-activate-body">
                        <span><strong>Kích hoạt</strong><small>Chuyển máy sang trạng thái hoạt động</small></span>
                        <form method="POST" action="{{ route('ops.activate.submit', $machine) }}" class="activate-inline-form">
                            @csrf
                            <input type="datetime-local" name="time" class="form-control form-control-sm" required>
                            <button class="btn btn-sm btn-success" type="submit">Kích hoạt</button>
                        </form>
                    </div>
                </div>

                <a class="operation-card operation-yellow" href="{{ route('ops.transfer.form', $machine) }}">
                    <span class="operation-icon"><svg viewBox="0 0 24 24"><path d="M7 7h11l-3-3m3 3-3 3M17 17H6l3 3m-3-3 3-3"/></svg></span>
                    <span><strong>Điều chuyển</strong><small>Chuyển dự án hoặc BCH</small></span>
                </a>

                <a class="operation-card operation-red" href="{{ route('ops.return.form', $machine) }}">
                    <span class="operation-icon"><svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg></span>
                    <span><strong>Trả máy</strong><small>Kết thúc hoạt động tại dự án</small></span>
                </a>

                <a class="operation-card operation-gray" href="{{ route('ops.assign-driver.form', $machine) }}">
                    <span class="operation-icon"><svg viewBox="0 0 24 24"><path d="M15 19a6 6 0 0 0-12 0m6-8a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm9-1v6m3-3h-6"/></svg></span>
                    <span><strong>Gán tài xế</strong><small>Phân công người vận hành</small></span>
                </a>

                <a class="operation-card operation-purple" href="{{ route('machine-documents.index', $machine) }}">
                    <span class="operation-icon"><svg viewBox="0 0 24 24"><path d="M6 3h9l3 3v15H6V3Zm3 7h6m-6 4h6m-6 4h4"/></svg></span>
                    <span><strong>Giấy tờ xe</strong><small>Đăng ký, đăng kiểm, bảo hiểm</small></span>
                </a>
            </div>
        </section>

        <section class="detail-section">
            <div class="section-heading-row">
                <div>
                    <div class="section-kicker">Lịch sử</div>
                    <h2 class="section-title">Quá trình sử dụng</h2>
                </div>
            </div>

            <div class="history-grid">
                <div class="app-card detail-table-card">
                    <div class="detail-card-header">
                        <div>
                            <h3>Lịch sử dự án</h3>
                            <span>{{ count($assignments) }} lần phân công</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table detail-table mb-0">
                            <thead>
                                <tr>
                                    <th>Dự án / BCH</th>
                                    <th>Thời gian vào</th>
                                    <th>Thời gian ra</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($assignments as $assignment)
                                    <tr>
                                        <td>
                                            <strong>{{ $assignment['project']['name'] ?? '-' }}</strong>
                                            <small>{{ $assignment['command_center']['name'] ?? 'Chưa có BCH' }}</small>
                                        </td>
                                        <td>{{ $assignment['time_in'] }}</td>
                                        <td>{{ $assignment['time_out'] ?? 'Đang hoạt động' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3"><div class="empty-state">Chưa có lịch sử dự án.</div></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="app-card detail-table-card">
                    <div class="detail-card-header">
                        <div>
                            <h3>Lịch sử tài xế</h3>
                            <span>{{ count($driverHistory) }} lần phân công</span>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('machines.change-driver.form', $machine) }}">Đổi tài xế</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table detail-table mb-0">
                            <thead>
                                <tr>
                                    <th>Tài xế</th>
                                    <th>Bắt đầu</th>
                                    <th>Kết thúc</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($driverHistory as $history)
                                    <tr>
                                        <td><strong>{{ $history['driver']['name'] ?? '-' }}</strong></td>
                                        <td>{{ $history['started_at'] }}</td>
                                        <td>{{ $history['ended_at'] ?? 'Hiện tại' }}</td>
                                        <td class="text-end">
                                            @if ($history['driver'])
                                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('drivers.show', $history['driver']['id']) }}">Xem</a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4"><div class="empty-state">Chưa có lịch sử tài xế.</div></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section class="detail-section">
            <div class="app-card detail-table-card">
                <div class="detail-card-header">
                    <div>
                        <div class="section-kicker">Dòng thời gian</div>
                        <h3>Lịch sử sự kiện</h3>
                        <span>Theo dõi toàn bộ thay đổi trạng thái và điều chuyển của thiết bị.</span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table detail-table event-table mb-0">
                        <thead>
                            <tr>
                                <th>Sự kiện</th>
                                <th>Thời gian</th>
                                <th>Nơi đi</th>
                                <th>Nơi đến</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($events as $event)
                                <tr>
                                    <td>
                                        <span class="event-type-badge">{{ $eventLabels[$event['type']] ?? $event['type'] }}</span>
                                    </td>
                                    <td>{{ $event['occurred_at'] }}</td>
                                    <td>
                                        <strong>{{ $event['from_project']['name'] ?? '-' }}</strong>
                                        <small>{{ $event['from_command_center']['name'] ?? '-' }}</small>
                                    </td>
                                    <td>
                                        <strong>{{ $event['to_project']['name'] ?? '-' }}</strong>
                                        <small>{{ $event['to_command_center']['name'] ?? '-' }}</small>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><div class="empty-state">Chưa có lịch sử sự kiện.</div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>


        <section class="detail-section" id="machine-timeline">
            <div class="section-heading-row timeline-heading-row">
                <div>
                    <div class="section-kicker">Timeline tổng hợp</div>
                    <h2 class="section-title">Toàn bộ lịch sử thiết bị</h2>
                    <p class="section-description">
                        Gộp hoạt động hệ thống, nghiệp vụ vận hành, tài xế và hồ sơ theo thời gian.
                    </p>
                </div>
                <div class="timeline-total">
                    <strong id="timelineVisibleCount">{{ count($timeline) }}</strong>
                    <span>sự kiện</span>
                </div>
            </div>

            <div class="app-card timeline-filter-card">
                <div class="timeline-filter-grid">
                    <div class="timeline-filter-field timeline-search-field">
                        <label for="timelineSearch">Tìm kiếm</label>
                        <div class="timeline-search-wrap">
                            <svg viewBox="0 0 24 24"><path d="m21 21-4.3-4.3M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
                            <input id="timelineSearch" class="form-control" type="search"
                                   placeholder="Tài xế, dự án, hồ sơ, sự kiện...">
                        </div>
                    </div>

                    <div class="timeline-filter-field">
                        <label for="timelineType">Loại sự kiện</label>
                        <select id="timelineType" class="form-select">
                            <option value="">Tất cả</option>
                            <option value="system">Hệ thống</option>
                            <option value="status">Trạng thái</option>
                            <option value="handover">Bàn giao</option>
                            <option value="transfer">Điều chuyển</option>
                            <option value="return">Trả máy</option>
                            <option value="driver">Tài xế</option>
                            <option value="document">Hồ sơ</option>
                        </select>
                    </div>

                    <div class="timeline-filter-field">
                        <label for="timelineDateFrom">Từ ngày</label>
                        <input id="timelineDateFrom" class="form-control" type="date">
                    </div>

                    <div class="timeline-filter-field">
                        <label for="timelineDateTo">Đến ngày</label>
                        <input id="timelineDateTo" class="form-control" type="date">
                    </div>

                    <div class="timeline-filter-actions">
                        <button id="timelineReset" class="btn btn-outline-secondary" type="button">Xóa lọc</button>
                    </div>
                </div>
            </div>

            <div class="app-card timeline-card">
                <div id="timelineList" class="machine-timeline-list">
                    @forelse ($timeline as $item)
                        <article
                            class="machine-timeline-item"
                            data-group="{{ $item['group'] }}"
                            data-date="{{ $item['occurred_at']->format('Y-m-d') }}"
                            data-search="{{ Str::lower($item['search'].' '.$item['meta'].' '.$item['actor']) }}"
                        >
                            <div class="timeline-axis">
                                <span class="timeline-icon timeline-{{ $item['tone'] }}">
                                    @switch($item['icon'])
                                        @case('handover')
                                            <svg viewBox="0 0 24 24"><path d="M4 12h16M14 6l6 6-6 6M4 6h5v12H4"/></svg>
                                            @break
                                        @case('play')
                                            <svg viewBox="0 0 24 24"><path d="m8 5 11 7-11 7V5Z"/></svg>
                                            @break
                                        @case('transfer')
                                            <svg viewBox="0 0 24 24"><path d="M7 7h11l-3-3m3 3-3 3M17 17H6l3 3m-3-3 3-3"/></svg>
                                            @break
                                        @case('return')
                                            <svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>
                                            @break
                                        @case('user')
                                            <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 8a7 7 0 0 0-14 0"/></svg>
                                            @break
                                        @case('document')
                                            <svg viewBox="0 0 24 24"><path d="M6 3h9l3 3v15H6V3Zm3 7h6m-6 4h6m-6 4h4"/></svg>
                                            @break
                                        @case('plus')
                                            <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                                            @break
                                        @default
                                            <svg viewBox="0 0 24 24"><path d="M12 12h.01"/></svg>
                                    @endswitch
                                </span>
                                @unless ($loop->last)
                                    <span class="timeline-connector"></span>
                                @endunless
                            </div>

                            <div class="timeline-event-card">
                                <div class="timeline-event-top">
                                    <div>
                                        <div class="timeline-event-title-row">
                                            <h3>{{ $item['title'] }}</h3>
                                            <span class="timeline-group-badge">{{ $item['group'] }}</span>
                                        </div>
                                        <time datetime="{{ $item['occurred_at']->toIso8601String() }}">
                                            {{ $item['occurred_at']->format('d/m/Y H:i') }}
                                            <span>•</span>
                                            {{ $item['occurred_at']->diffForHumans() }}
                                        </time>
                                    </div>
                                </div>

                                <p>{{ $item['description'] }}</p>

                                @if ($item['meta'] || $item['actor'])
                                    <div class="timeline-event-meta">
                                        @if ($item['meta'])
                                            <span>{{ $item['meta'] }}</span>
                                        @endif
                                        @if ($item['actor'])
                                            <span>Người thao tác: {{ $item['actor'] }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="empty-state empty-state-large">Chưa có dữ liệu timeline cho thiết bị này.</div>
                    @endforelse
                </div>

                <div id="timelineNoResult" class="empty-state empty-state-large d-none">
                    Không có sự kiện phù hợp với bộ lọc.
                </div>
            </div>
        </section>

        <style>
            .timeline-heading-row{align-items:flex-end}
            .timeline-total{display:flex;align-items:baseline;gap:6px;padding:10px 14px;border:1px solid #dbe5f0;border-radius:12px;background:#fff}
            .timeline-total strong{font-size:22px;color:#0f172a}
            .timeline-total span{font-size:12px;color:#64748b}
            .timeline-filter-card{margin-bottom:16px;padding:18px}
            .timeline-filter-grid{display:grid;grid-template-columns:minmax(240px,1fr) 170px 155px 155px auto;gap:12px;align-items:end}
            .timeline-filter-field label{display:block;margin-bottom:6px;color:#475569;font-size:12px;font-weight:700}
            .timeline-search-wrap{position:relative}
            .timeline-search-wrap svg{position:absolute;left:12px;top:50%;width:17px;height:17px;fill:none;stroke:#94a3b8;stroke-width:1.8;transform:translateY(-50%)}
            .timeline-search-wrap input{padding-left:38px}
            .timeline-filter-actions{display:flex;align-items:flex-end}
            .timeline-card{padding:20px}
            .machine-timeline-list{display:flex;flex-direction:column}
            .machine-timeline-item{display:grid;grid-template-columns:46px minmax(0,1fr);gap:14px}
            .timeline-axis{display:flex;flex-direction:column;align-items:center}
            .timeline-icon{position:relative;z-index:2;display:flex;width:38px;height:38px;flex:0 0 38px;align-items:center;justify-content:center;border-radius:12px}
            .timeline-icon svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
            .timeline-blue{background:#dbeafe;color:#1d4ed8}
            .timeline-green{background:#dcfce7;color:#15803d}
            .timeline-yellow{background:#fef3c7;color:#a16207}
            .timeline-red{background:#fee2e2;color:#b91c1c}
            .timeline-purple{background:#ede9fe;color:#6d28d9}
            .timeline-orange{background:#ffedd5;color:#c2410c}
            .timeline-connector{width:2px;min-height:72px;flex:1;background:#e2e8f0}
            .timeline-event-card{margin-bottom:14px;padding:16px 18px;border:1px solid #e2e8f0;border-radius:14px;background:#fff;transition:.15s ease}
            .timeline-event-card:hover{border-color:#cbd5e1;box-shadow:0 6px 18px rgba(15,23,42,.05)}
            .timeline-event-title-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
            .timeline-event-title-row h3{margin:0;color:#0f172a;font-size:15px;font-weight:800}
            .timeline-group-badge{padding:3px 8px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase}
            .timeline-event-card time{display:block;margin-top:4px;color:#94a3b8;font-size:11px}
            .timeline-event-card p{margin:10px 0 0;color:#475569;font-size:13px;line-height:1.55}
            .timeline-event-meta{display:flex;flex-wrap:wrap;gap:7px 18px;margin-top:10px;color:#64748b;font-size:11px}
            .timeline-event-meta span+span{position:relative}
            .timeline-event-meta span+span:before{content:"";position:absolute;left:-10px;top:50%;width:3px;height:3px;border-radius:50%;background:#cbd5e1;transform:translateY(-50%)}
            @media(max-width:1100px){
                .timeline-filter-grid{grid-template-columns:1fr 1fr}
                .timeline-search-field{grid-column:1/-1}
            }
            @media(max-width:700px){
                .timeline-heading-row{align-items:flex-start}
                .timeline-filter-grid{grid-template-columns:1fr}
                .timeline-search-field{grid-column:auto}
                .timeline-total{margin-top:10px}
            }
        </style>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const search = document.getElementById('timelineSearch');
                const type = document.getElementById('timelineType');
                const from = document.getElementById('timelineDateFrom');
                const to = document.getElementById('timelineDateTo');
                const reset = document.getElementById('timelineReset');
                const items = Array.from(document.querySelectorAll('.machine-timeline-item'));
                const noResult = document.getElementById('timelineNoResult');
                const visibleCount = document.getElementById('timelineVisibleCount');

                const normalize = value => (value || '')
                    .toLocaleLowerCase('vi-VN')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '');

                function applyTimelineFilters() {
                    const keyword = normalize(search.value.trim());
                    let count = 0;

                    items.forEach(item => {
                        const searchable = normalize(item.dataset.search);
                        const matchesKeyword = !keyword || searchable.includes(keyword);
                        const matchesType = !type.value || item.dataset.group === type.value;
                        const matchesFrom = !from.value || item.dataset.date >= from.value;
                        const matchesTo = !to.value || item.dataset.date <= to.value;
                        const visible = matchesKeyword && matchesType && matchesFrom && matchesTo;

                        item.classList.toggle('d-none', !visible);

                        if (visible) {
                            count++;
                        }
                    });

                    visibleCount.textContent = count;
                    noResult.classList.toggle('d-none', count !== 0 || items.length === 0);
                }

                [search, type, from, to].forEach(element => {
                    element.addEventListener(element === search ? 'input' : 'change', applyTimelineFilters);
                });

                reset.addEventListener('click', function () {
                    search.value = '';
                    type.value = '';
                    from.value = '';
                    to.value = '';
                    applyTimelineFilters();
                });
            });
        </script>

        <section class="detail-section">
            <div class="section-heading-row">
                <div>
                    <div class="section-kicker">Tài liệu</div>
                    <h2 class="section-title">Biên bản liên quan</h2>
                    <p class="section-description">Xem nhanh ảnh, tải file hoặc bổ sung biên bản còn thiếu.</p>
                </div>
            </div>

            <div class="proof-grid">
                @forelse ($proofEvents as $event)
                    @php
                        $proofPath = $event->proof_file_path;
                        $proofUrl = $proofPath ? Storage::disk('public')->url($proofPath) : null;
                        $isImage = $proofPath ? Str::of($proofPath)->lower()->endsWith(['.jpg', '.jpeg', '.png', '.webp', '.gif']) : false;
                        $typeLabel = match ($event->type) {
                            'HANDOVER' => 'Biên bản bàn giao',
                            'TRANSFER' => 'Biên bản điều chuyển',
                            'RETURN' => 'Biên bản trả',
                            default => $event->type,
                        };
                    @endphp
                    <article class="proof-card app-card {{ !$proofUrl ? 'proof-card-missing' : '' }}">
                        <div class="proof-preview">
                            @if ($proofUrl && $isImage)
                                <img src="{{ $proofUrl }}" alt="{{ $typeLabel }}">
                            @elseif ($proofUrl)
                                <div class="proof-file-icon"><svg viewBox="0 0 24 24"><path d="M6 3h9l3 3v15H6V3Zm3 7h6m-6 4h6"/></svg></div>
                            @else
                                <div class="proof-file-icon proof-file-missing"><svg viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M6 3h9l3 3v15H6V3Z"/></svg></div>
                            @endif
                        </div>
                        <div class="proof-body">
                            <div class="proof-title-row">
                                <div>
                                    <span class="proof-type">{{ $typeLabel }}</span>
                                    <h3>{{ $event->occurred_at }}</h3>
                                </div>
                                @if (!$proofUrl)
                                    <span class="notice-badge notice-danger">Thiếu file</span>
                                @endif
                            </div>
                            <div class="proof-route">
                                <span><small>Từ</small>{{ $event->fromProject?->name ?? '-' }} / {{ $event->fromCommandCenter?->name ?? '-' }}</span>
                                <svg viewBox="0 0 24 24"><path d="M5 12h14m-5-5 5 5-5 5"/></svg>
                                <span><small>Đến</small>{{ $event->toProject?->name ?? '-' }} / {{ $event->toCommandCenter?->name ?? '-' }}</span>
                            </div>
                            <div class="proof-actions">
                                @if ($proofUrl && $isImage)
                                    <a class="btn btn-sm btn-outline-primary" href="{{ $proofUrl }}" target="_blank">Xem ảnh</a>
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ $proofUrl }}" download>Tải ảnh</a>
                                @elseif ($proofUrl)
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ $proofUrl }}" download>Tải file</a>
                                @elseif ($event->type === 'HANDOVER')
                                    <a class="btn btn-sm btn-danger" href="{{ route('machine-events.edit-proof', [$machine, $event]) }}">Bổ sung biên bản</a>
                                @endif

                                <a class="btn btn-sm btn-outline-warning" href="{{ route('machine-events.edit-proof', [$machine, $event]) }}">Sửa</a>
                                <form method="POST" action="{{ route('machine-events.destroy-proof', [$machine, $event]) }}" onsubmit="return confirm('Xóa file biên bản này?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Xóa</button>
                                </form>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="app-card empty-state empty-state-large">Chưa có biên bản liên quan đến máy này.</div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
