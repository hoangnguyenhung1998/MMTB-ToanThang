@extends('layouts.app')

@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Dashboard variables with safe fallbacks
    |--------------------------------------------------------------------------
    | File này tương thích với dữ liệu DashboardController Phase 4A.
    | Các giá trị mặc định giúp trang không lỗi nếu một biến chưa được truyền.
    */

    $statuses = $statuses ?? ['WAIT_HANDOVER', 'HANDED_OVER', 'ACTIVE', 'RETURNED'];
    $companies = $companies ?? ['VINCONS', 'VINALPHA'];
    $statusCounts = $statusCounts ?? collect();
    $companyStatusCounts = $companyStatusCounts ?? [];
    $projectCounts = $projectCounts ?? collect();
    $expiryItems = $expiryItems ?? collect();
    $recentActivities = $recentActivities ?? collect();

    $totalMachines = (int) ($totalMachines ?? 0);
    $returnedNotSyncedCount = (int) ($returnedNotSyncedCount ?? 0);
    $missingGpsCount = (int) ($missingGpsCount ?? 0);

    $machineExpiredCount = (int) ($machineExpiredCount ?? 0);
    $driverExpiredCount = (int) ($driverExpiredCount ?? 0);
    $machineExpiringCount = (int) ($machineExpiringCount ?? 0);
    $driverExpiringCount = (int) ($driverExpiringCount ?? 0);

    $statusMeta = [
        'WAIT_HANDOVER' => [
            'label' => 'Chờ bàn giao',
            'class' => 'warning',
            'icon' => '⌛',
        ],
        'HANDED_OVER' => [
            'label' => 'Chờ kích hoạt',
            'class' => 'primary',
            'icon' => '↗',
        ],
        'ACTIVE' => [
            'label' => 'Đang hoạt động',
            'class' => 'success',
            'icon' => '✓',
        ],
        'RETURNED' => [
            'label' => 'Đã trả',
            'class' => 'secondary',
            'icon' => '↙',
        ],
    ];

    $chartLabels = [];
    $chartValues = [];

    foreach ($statuses as $chartStatus) {
        $chartLabels[] = $statusMeta[$chartStatus]['label'] ?? $chartStatus;
        $chartValues[] = (int) ($statusCounts[$chartStatus] ?? 0);
    }

    $calculatedMaxProjectCount = 1;
    foreach ($projectCounts as $projectRow) {
        if ((int) $projectRow->total > $calculatedMaxProjectCount) {
            $calculatedMaxProjectCount = (int) $projectRow->total;
        }
    }

    $maxProjectCount = max(1, (int) ($maxProjectCount ?? $calculatedMaxProjectCount));

    $expiredTotal = $machineExpiredCount + $driverExpiredCount;
    $expiringTotal = $machineExpiringCount + $driverExpiringCount;
@endphp

<div class="container-fluid dashboard-v2">

    {{-- Page header --}}
    <div class="dashboard-page-header">
        <div>
            <div class="dashboard-eyebrow">TRUNG TÂM ĐIỀU HÀNH</div>
            <h1 class="dashboard-title">Bảng điều khiển vận hành</h1>
            <p class="dashboard-subtitle">
                Tổng quan thiết bị, tình trạng hồ sơ và các công việc cần ưu tiên xử lý.
            </p>
        </div>

        <div class="dashboard-header-actions">
            <a href="{{ route('machines.index') }}" class="btn btn-outline-secondary">
                Danh sách máy
            </a>
            <a href="{{ route('machines.create') }}" class="btn btn-primary">
                + Thêm thiết bị
            </a>
        </div>
    </div>

    {{-- KPI cards --}}
    <div class="dashboard-kpi-grid">
        <a href="{{ route('machines.index') }}" class="dashboard-kpi-card dashboard-kpi-dark">
            <div class="dashboard-kpi-head">
                <span class="dashboard-kpi-icon">▦</span>
                <span class="dashboard-kpi-arrow">→</span>
            </div>
            <div class="dashboard-kpi-label">Tổng thiết bị</div>
            <div class="dashboard-kpi-value">{{ number_format($totalMachines) }}</div>
            <div class="dashboard-kpi-foot">Toàn bộ máy trong hệ thống</div>
        </a>

        @foreach (['ACTIVE', 'WAIT_HANDOVER', 'HANDED_OVER', 'RETURNED'] as $status)
            @php
                $meta = $statusMeta[$status];
                $count = (int) ($statusCounts[$status] ?? 0);
                $percent = $totalMachines > 0 ? round(($count / $totalMachines) * 100, 1) : 0;
            @endphp

            <a href="{{ route('machines.index', ['status' => $status]) }}"
               class="dashboard-kpi-card dashboard-kpi-{{ $meta['class'] }}">
                <div class="dashboard-kpi-head">
                    <span class="dashboard-kpi-icon">{{ $meta['icon'] }}</span>
                    <span class="dashboard-kpi-arrow">→</span>
                </div>
                <div class="dashboard-kpi-label">{{ $meta['label'] }}</div>
                <div class="dashboard-kpi-value">{{ number_format($count) }}</div>
                <div class="dashboard-kpi-foot">{{ $percent }}% tổng thiết bị</div>
            </a>
        @endforeach
    </div>

    {{-- Priority work + chart --}}
    <div class="row g-4 mt-1">
        <div class="col-xl-7">
            <div class="dashboard-panel h-100">
                <div class="dashboard-panel-head">
                    <div>
                        <h2>Việc cần ưu tiên</h2>
                        <p>Các nhóm dữ liệu cần được xử lý sớm.</p>
                    </div>
                </div>

                <div class="dashboard-alert-list">
                    <a href="{{ route('machines.index', ['status' => 'RETURNED', 'returned_to_app' => '0']) }}"
                       class="dashboard-alert dashboard-alert-danger">
                        <span class="dashboard-alert-icon">↙</span>
                        <span class="dashboard-alert-main">
                            <strong>Máy chưa đẩy app trả</strong>
                            <small>Hoàn tất trạng thái trả trên ứng dụng vận hành</small>
                        </span>
                        <span class="dashboard-alert-count">{{ $returnedNotSyncedCount }}</span>
                        <span class="dashboard-alert-go">→</span>
                    </a>

                    <a href="{{ route('machines.index', ['gps_file_added' => '0']) }}"
                       class="dashboard-alert dashboard-alert-warning">
                        <span class="dashboard-alert-icon">⌖</span>
                        <span class="dashboard-alert-main">
                            <strong>Thiết bị thiếu file GPS</strong>
                            <small>Bổ sung file GPS cho thiết bị đang quản lý</small>
                        </span>
                        <span class="dashboard-alert-count">{{ $missingGpsCount }}</span>
                        <span class="dashboard-alert-go">→</span>
                    </a>

                    <a href="{{ route('expiries.index') }}"
                       class="dashboard-alert dashboard-alert-danger-soft">
                        <span class="dashboard-alert-icon">!</span>
                        <span class="dashboard-alert-main">
                            <strong>Giấy tờ đã hết hạn</strong>
                            <small>{{ $machineExpiredCount }} hồ sơ máy · {{ $driverExpiredCount }} hồ sơ tài xế</small>
                        </span>
                        <span class="dashboard-alert-count">{{ $expiredTotal }}</span>
                        <span class="dashboard-alert-go">→</span>
                    </a>

                    <a href="{{ route('expiries.index') }}"
                       class="dashboard-alert dashboard-alert-info">
                        <span class="dashboard-alert-icon">◷</span>
                        <span class="dashboard-alert-main">
                            <strong>Giấy tờ sắp hết hạn</strong>
                            <small>Trong vòng 30 ngày tới</small>
                        </span>
                        <span class="dashboard-alert-count">{{ $expiringTotal }}</span>
                        <span class="dashboard-alert-go">→</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="dashboard-panel h-100">
                <div class="dashboard-panel-head">
                    <div>
                        <h2>Cơ cấu trạng thái</h2>
                        <p>Tỷ trọng thiết bị theo trạng thái hiện tại.</p>
                    </div>
                </div>

                <div class="dashboard-chart-box">
                    <canvas id="statusChart"></canvas>
                </div>

                <div class="dashboard-legend">
                    @foreach ($statuses as $status)
                        @php
                            $meta = $statusMeta[$status] ?? [
                                'label' => $status,
                                'class' => 'secondary',
                            ];
                        @endphp

                        <div class="dashboard-legend-item">
                            <span class="dashboard-legend-dot dashboard-dot-{{ $meta['class'] }}"></span>
                            <span>{{ $meta['label'] }}</span>
                            <strong>{{ number_format((int) ($statusCounts[$status] ?? 0)) }}</strong>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Projects + companies --}}
    <div class="row g-4 mt-1">
        <div class="col-xl-7">
            <div class="dashboard-panel h-100">
                <div class="dashboard-panel-head">
                    <div>
                        <h2>Phân bổ theo dự án</h2>
                        <p>Số máy đang có assignment mở tại từng dự án.</p>
                    </div>
                </div>

                <div class="dashboard-project-list">
                    @forelse ($projectCounts as $row)
                        @php
                            $projectCount = (int) $row->total;
                            $projectPercent = min(100, round(($projectCount / $maxProjectCount) * 100));
                            $projectUrl = $row->project_id
                                ? route('machines.index', ['project_id' => $row->project_id])
                                : route('machines.index');
                        @endphp

                        <a href="{{ $projectUrl }}" class="dashboard-project-item">
                            <div class="dashboard-project-head">
                                <span>{{ $row->project?->name ?? 'Chưa xác định dự án' }}</span>
                                <strong>{{ number_format($projectCount) }} máy</strong>
                            </div>
                            <div class="dashboard-progress">
                                <span style="width: {{ $projectPercent }}%"></span>
                            </div>
                        </a>
                    @empty
                        <div class="dashboard-empty">
                            <strong>Chưa có dữ liệu dự án</strong>
                            <span>Thiết bị chưa có assignment đang mở.</span>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="dashboard-panel h-100">
                <div class="dashboard-panel-head">
                    <div>
                        <h2>Phân bổ theo công ty</h2>
                        <p>Tình trạng thiết bị của VINCONS và VINALPHA.</p>
                    </div>
                </div>

                <div class="dashboard-company-list">
                    @foreach ($companies as $company)
                        @php
                            $companyTotal = 0;
                            foreach (($companyStatusCounts[$company] ?? []) as $companyStatusCount) {
                                $companyTotal += (int) $companyStatusCount;
                            }
                            $companyPercent = $totalMachines > 0
                                ? round(($companyTotal / $totalMachines) * 100)
                                : 0;
                        @endphp

                        <div class="dashboard-company-card">
                            <div class="dashboard-company-head">
                                <div>
                                    <strong>{{ $company }}</strong>
                                    <span>{{ number_format($companyTotal) }} thiết bị</span>
                                </div>
                                <b>{{ $companyPercent }}%</b>
                            </div>

                            <div class="dashboard-company-status">
                                @foreach ($statuses as $status)
                                    @php
                                        $meta = $statusMeta[$status] ?? [
                                            'label' => $status,
                                            'class' => 'secondary',
                                        ];
                                    @endphp

                                    <span title="{{ $meta['label'] }}">
                                        <i class="dashboard-legend-dot dashboard-dot-{{ $meta['class'] }}"></i>
                                        {{ number_format((int) ($companyStatusCounts[$company][$status] ?? 0)) }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Expiries + activities --}}
    <div class="row g-4 mt-1">
        <div class="col-xl-7">
            <div class="dashboard-panel h-100">
                <div class="dashboard-panel-head">
                    <div>
                        <h2>Hồ sơ cần xử lý</h2>
                        <p>Các giấy tờ đã quá hạn hoặc gần ngày hết hạn.</p>
                    </div>
                    <a href="{{ route('expiries.index') }}" class="dashboard-panel-link">Xem tất cả →</a>
                </div>

                <div class="dashboard-expiry-list">
                    @forelse ($expiryItems as $item)
                        @php
                            $daysDiff = (int) ($item['days_diff'] ?? 0);
                            $isExpired = $daysDiff < 0;

                            if (($item['type'] ?? null) === 'machine') {
                                $detailUrl = route('machine-documents.index', $item['machine_id']);
                                $ownerType = 'Thiết bị';
                            } else {
                                $detailUrl = route('drivers.show', $item['driver_id']);
                                $ownerType = 'Tài xế';
                            }

                            try {
                                $formattedExpiryDate = \Carbon\Carbon::parse($item['expiry_date'])->format('d/m/Y');
                            } catch (\Throwable $exception) {
                                $formattedExpiryDate = '-';
                            }
                        @endphp

                        <a href="{{ $detailUrl }}"
                           class="dashboard-expiry-item {{ $isExpired ? 'dashboard-expiry-expired' : 'dashboard-expiry-warning' }}">
                            <span class="dashboard-expiry-icon">{{ $isExpired ? '!' : '◷' }}</span>

                            <span class="dashboard-expiry-main">
                                <strong>{{ $item['label'] ?? '-' }}</strong>
                                <small>{{ $ownerType }} · {{ $item['doc_type'] ?? '-' }}</small>
                            </span>

                            <span class="dashboard-expiry-date">{{ $formattedExpiryDate }}</span>

                            <span class="dashboard-expiry-status">
                                @if ($isExpired)
                                    Quá hạn {{ abs($daysDiff) }} ngày
                                @else
                                    Còn {{ $daysDiff }} ngày
                                @endif
                            </span>
                        </a>
                    @empty
                        <div class="dashboard-empty">
                            <strong>Không có hồ sơ cần xử lý</strong>
                            <span>Chưa có giấy tờ sắp hoặc đã hết hạn.</span>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="dashboard-panel h-100">
                <div class="dashboard-panel-head">
                    <div>
                        <h2>Hoạt động gần đây</h2>
                        <p>Các thay đổi mới nhất trên thiết bị.</p>
                    </div>
                </div>

                <div class="dashboard-timeline">
                    @forelse ($recentActivities as $activity)
                        @php
                            $activityUrl = !empty($activity['machine_id'])
                                ? route('machines.show', $activity['machine_id'])
                                : '#';

                            try {
                                $activityTime = \Carbon\Carbon::parse($activity['occurred_at'])->format('d/m/Y H:i');
                            } catch (\Throwable $exception) {
                                $activityTime = '-';
                            }

                            $activityTone = $activity['tone'] ?? 'secondary';
                        @endphp

                        <a href="{{ $activityUrl }}" class="dashboard-timeline-item">
                            <span class="dashboard-timeline-marker dashboard-marker-{{ $activityTone }}">
                                {{ $activity['icon'] ?? '•' }}
                            </span>

                            <span class="dashboard-timeline-main">
                                <span>
                                    <strong>{{ $activity['asset_code'] ?? '-' }}</strong>
                                    {{ $activity['label'] ?? 'Cập nhật thiết bị' }}
                                </span>
                                <small>{{ $activityTime }}</small>
                            </span>
                        </a>
                    @empty
                        <div class="dashboard-empty">
                            <strong>Chưa có hoạt động</strong>
                            <span>Lịch sử sự kiện mới sẽ xuất hiện tại đây.</span>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

{{-- CSS tự chứa để trang hoạt động ngay, chưa phụ thuộc app.css --}}
<style>
    .dashboard-v2 {
        padding-bottom: 32px;
    }

    .dashboard-page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 24px;
        margin-bottom: 24px;
    }

    .dashboard-eyebrow {
        margin-bottom: 6px;
        color: #64748b;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .08em;
    }

    .dashboard-title {
        margin: 0;
        color: #0f172a;
        font-size: 30px;
        font-weight: 800;
        letter-spacing: -.03em;
    }

    .dashboard-subtitle {
        margin: 8px 0 0;
        color: #64748b;
        font-size: 14px;
    }

    .dashboard-header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .dashboard-kpi-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 16px;
    }

    .dashboard-kpi-card {
        min-height: 172px;
        padding: 20px;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: #fff;
        color: #0f172a;
        text-decoration: none;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
        transition: transform .18s ease, box-shadow .18s ease;
    }

    .dashboard-kpi-card:hover {
        color: inherit;
        transform: translateY(-2px);
        box-shadow: 0 14px 30px rgba(15, 23, 42, .08);
    }

    .dashboard-kpi-dark {
        border-color: #0f172a;
        background: #0f172a;
        color: #fff;
    }

    .dashboard-kpi-dark:hover {
        color: #fff;
    }

    .dashboard-kpi-success {
        border-top: 4px solid #16a36a;
    }

    .dashboard-kpi-warning {
        border-top: 4px solid #d6a227;
    }

    .dashboard-kpi-primary {
        border-top: 4px solid #4077e8;
    }

    .dashboard-kpi-secondary {
        border-top: 4px solid #77808f;
    }

    .dashboard-kpi-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .dashboard-kpi-icon {
        display: inline-flex;
        width: 38px;
        height: 38px;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background: rgba(148, 163, 184, .14);
        font-size: 18px;
        font-weight: 800;
    }

    .dashboard-kpi-arrow {
        opacity: .6;
        font-size: 20px;
    }

    .dashboard-kpi-label {
        margin-top: 20px;
        color: #64748b;
        font-size: 13px;
        font-weight: 700;
    }

    .dashboard-kpi-dark .dashboard-kpi-label,
    .dashboard-kpi-dark .dashboard-kpi-foot {
        color: #cbd5e1;
    }

    .dashboard-kpi-value {
        margin-top: 2px;
        font-size: 34px;
        font-weight: 800;
        line-height: 1.1;
        letter-spacing: -.04em;
    }

    .dashboard-kpi-foot {
        margin-top: 8px;
        color: #64748b;
        font-size: 12px;
    }

    .dashboard-panel {
        padding: 22px;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
    }

    .dashboard-panel-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        margin-bottom: 18px;
    }

    .dashboard-panel-head h2 {
        margin: 0;
        color: #0f172a;
        font-size: 17px;
        font-weight: 800;
    }

    .dashboard-panel-head p {
        margin: 5px 0 0;
        color: #64748b;
        font-size: 13px;
    }

    .dashboard-panel-link {
        color: #2563eb;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }

    .dashboard-alert-list,
    .dashboard-project-list,
    .dashboard-company-list,
    .dashboard-expiry-list,
    .dashboard-timeline {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .dashboard-alert {
        display: grid;
        grid-template-columns: 44px minmax(0, 1fr) auto 18px;
        align-items: center;
        gap: 12px;
        min-height: 72px;
        padding: 12px 14px;
        border: 1px solid transparent;
        border-radius: 14px;
        color: #0f172a;
        text-decoration: none;
    }

    .dashboard-alert:hover {
        color: #0f172a;
        filter: brightness(.985);
    }

    .dashboard-alert-danger {
        border-color: #fecaca;
        background: #fff1f2;
    }

    .dashboard-alert-danger-soft {
        border-color: #fed7aa;
        background: #fff7ed;
    }

    .dashboard-alert-warning {
        border-color: #fde68a;
        background: #fffbeb;
    }

    .dashboard-alert-info {
        border-color: #bfdbfe;
        background: #eff6ff;
    }

    .dashboard-alert-icon {
        display: inline-flex;
        width: 40px;
        height: 40px;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background: rgba(255, 255, 255, .75);
        font-size: 18px;
        font-weight: 800;
    }

    .dashboard-alert-main {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .dashboard-alert-main strong {
        font-size: 14px;
    }

    .dashboard-alert-main small {
        margin-top: 3px;
        color: #64748b;
    }

    .dashboard-alert-count {
        min-width: 34px;
        text-align: right;
        font-size: 24px;
        font-weight: 800;
    }

    .dashboard-alert-go {
        color: #64748b;
    }

    .dashboard-chart-box {
        position: relative;
        height: 245px;
    }

    .dashboard-legend {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 14px;
    }

    .dashboard-legend-item {
        display: grid;
        grid-template-columns: 10px minmax(0, 1fr) auto;
        align-items: center;
        gap: 8px;
        color: #475569;
        font-size: 13px;
    }

    .dashboard-legend-item strong {
        color: #0f172a;
    }

    .dashboard-legend-dot {
        display: inline-block;
        width: 9px;
        height: 9px;
        border-radius: 999px;
    }

    .dashboard-dot-success {
        background: #1f9d68;
    }

    .dashboard-dot-warning {
        background: #d6a227;
    }

    .dashboard-dot-primary {
        background: #4077e8;
    }

    .dashboard-dot-secondary {
        background: #77808f;
    }

    .dashboard-project-item {
        display: block;
        padding: 11px 0;
        color: #0f172a;
        text-decoration: none;
    }

    .dashboard-project-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 8px;
        font-size: 13px;
    }

    .dashboard-project-head span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dashboard-progress {
        height: 8px;
        overflow: hidden;
        border-radius: 999px;
        background: #edf2f7;
    }

    .dashboard-progress span {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #315fca, #5b8def);
    }

    .dashboard-company-card {
        padding: 16px;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #f8fafc;
    }

    .dashboard-company-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
    }

    .dashboard-company-head > div {
        display: flex;
        flex-direction: column;
    }

    .dashboard-company-head strong {
        color: #0f172a;
        font-size: 15px;
    }

    .dashboard-company-head span {
        margin-top: 3px;
        color: #64748b;
        font-size: 12px;
    }

    .dashboard-company-head b {
        color: #315fca;
        font-size: 24px;
    }

    .dashboard-company-status {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-top: 14px;
        color: #475569;
        font-size: 13px;
    }

    .dashboard-company-status span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .dashboard-expiry-item {
        display: grid;
        grid-template-columns: 38px minmax(0, 1fr) auto auto;
        align-items: center;
        gap: 12px;
        padding: 13px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        color: #0f172a;
        text-decoration: none;
    }

    .dashboard-expiry-item:hover {
        color: #0f172a;
        background: #f8fafc;
    }

    .dashboard-expiry-expired {
        border-left: 4px solid #dc3545;
    }

    .dashboard-expiry-warning {
        border-left: 4px solid #d6a227;
    }

    .dashboard-expiry-icon {
        display: inline-flex;
        width: 34px;
        height: 34px;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #f1f5f9;
        font-weight: 800;
    }

    .dashboard-expiry-main {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .dashboard-expiry-main strong {
        overflow: hidden;
        font-size: 14px;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dashboard-expiry-main small,
    .dashboard-expiry-date {
        color: #64748b;
        font-size: 12px;
    }

    .dashboard-expiry-status {
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .dashboard-expiry-expired .dashboard-expiry-status {
        color: #dc3545;
    }

    .dashboard-expiry-warning .dashboard-expiry-status {
        color: #a16207;
    }

    .dashboard-timeline {
        position: relative;
    }

    .dashboard-timeline-item {
        display: grid;
        grid-template-columns: 36px minmax(0, 1fr);
        gap: 12px;
        padding: 9px 0;
        color: #0f172a;
        text-decoration: none;
    }

    .dashboard-timeline-marker {
        display: inline-flex;
        width: 34px;
        height: 34px;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #eef2ff;
        color: #315fca;
        font-weight: 800;
    }

    .dashboard-marker-success {
        background: #dcfce7;
        color: #15803d;
    }

    .dashboard-marker-warning {
        background: #fef3c7;
        color: #a16207;
    }

    .dashboard-marker-danger {
        background: #fee2e2;
        color: #b91c1c;
    }

    .dashboard-marker-primary {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .dashboard-marker-secondary,
    .dashboard-marker-neutral {
        background: #e2e8f0;
        color: #475569;
    }

    .dashboard-timeline-main {
        display: flex;
        flex-direction: column;
        padding-top: 2px;
    }

    .dashboard-timeline-main span {
        font-size: 13px;
    }

    .dashboard-timeline-main strong {
        margin-right: 5px;
    }

    .dashboard-timeline-main small {
        margin-top: 4px;
        color: #64748b;
        font-size: 12px;
    }

    .dashboard-empty {
        display: flex;
        min-height: 125px;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 20px;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        color: #64748b;
        text-align: center;
    }

    .dashboard-empty strong {
        color: #334155;
        font-size: 14px;
    }

    .dashboard-empty span {
        margin-top: 5px;
        font-size: 12px;
    }

    @media (max-width: 1399.98px) {
        .dashboard-kpi-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 991.98px) {
        .dashboard-page-header {
            flex-direction: column;
        }

        .dashboard-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 575.98px) {
        .dashboard-title {
            font-size: 25px;
        }

        .dashboard-header-actions {
            width: 100%;
        }

        .dashboard-header-actions .btn {
            flex: 1;
        }

        .dashboard-kpi-grid {
            grid-template-columns: 1fr;
        }

        .dashboard-kpi-card {
            min-height: 150px;
        }

        .dashboard-alert {
            grid-template-columns: 40px minmax(0, 1fr) auto;
        }

        .dashboard-alert-go {
            display: none;
        }

        .dashboard-expiry-item {
            grid-template-columns: 36px minmax(0, 1fr);
        }

        .dashboard-expiry-date,
        .dashboard-expiry-status {
            grid-column: 2;
        }

        .dashboard-legend {
            grid-template-columns: 1fr;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var canvas = document.getElementById('statusChart');

        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        var chartLabels = @json($chartLabels);
        var chartValues = @json($chartValues);

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: chartLabels,
                datasets: [{
                    data: chartValues,
                    backgroundColor: [
                        '#d6a227',
                        '#4077e8',
                        '#1f9d68',
                        '#77808f'
                    ],
                    borderWidth: 0,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return ' ' + context.label + ': ' + context.raw + ' máy';
                            }
                        }
                    }
                }
            }
        });
    });
</script>
@endsection
