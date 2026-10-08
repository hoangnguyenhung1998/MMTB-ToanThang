@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <h1 class="h4">Sửa liên kết tất cả kỳ</h1>
    <p>Tất cả {{ count($periods) }} kỳ đã tồn tại được kiểm tra theo quyền thao tác của bạn, gồm cả các kỳ ngoài trang và bộ lọc hiện tại.</p>
    @php $statusCounts = collect($periods)->countBy('status'); @endphp
    <p>Đã xử lý: {{ $statusCounts['COMPLETED'] ?? 0 }} kỳ; thất bại: {{ $statusCounts['FAILED'] ?? 0 }};
        bỏ qua: {{ $statusCounts['SKIPPED'] ?? 0 }}; {{ $run ? 'chờ xử lý' : 'dự kiến áp dụng' }}: {{ $statusCounts['PENDING'] ?? 0 }}.</p>
    @if ($run)
        @php
            $runLabels = ['PENDING' => 'Đã xếp lịch', 'RUNNING' => 'Đang xử lý', 'COMPLETED' => 'Hoàn tất', 'PARTIAL' => 'Hoàn tất một phần, có kỳ thất bại', 'COMPLETED_WITH_REVIEW' => 'Đã xử lý, còn trường hợp cần kiểm tra'];
        @endphp
        <div class="alert alert-info">
            {{ $runLabels[$run->status] ?? $run->status }}. Mã lượt: {{ $run->id }}.
            Công việc được xử lý bởi lịch chạy của hệ thống và tiếp tục khi bạn đóng trang.
            Nếu trạng thái chưa tiến triển, cần kiểm tra lịch chạy hệ thống.
        </div>
        <a class="btn btn-outline-primary mb-3" href="{{ route('reconciliation-periods.repair-all.show', $run) }}">Tải lại kết quả</a>
        <a class="btn btn-outline-secondary mb-3" href="{{ route('reconciliation-periods.repair-all.preview') }}">Xem trước lại / thử lại</a>
    @else
        <div class="alert alert-warning">
            Đây là xem trước chỉ đọc. Kỳ đã chốt/khóa và dữ liệu được bảo vệ được giữ nguyên.
            Khi áp dụng, từng kỳ được kiểm tra lại; dữ liệu đã thay đổi sẽ bị bỏ qua để xem trước lại.
            Dòng trùng chỉ được loại khi có bằng chứng đầy đủ; giờ/ảnh/GPS/pairing không được tính lại.
        </div>
    @endif
    <div class="table-responsive mb-3">
        <table class="table table-bordered align-middle">
            <thead><tr><th>Kỳ</th><th>Trạng thái</th><th>Liên kết</th><th>Duplicate hợp nhất</th><th>Không BCH</th><th>Dòng loại</th><th>Protected / unresolved</th><th>Lý do</th></tr></thead>
            <tbody>
            @foreach ($periods as $entry)
                @php
                    $counts = $run ? ($entry['result'] ?? []) : ($entry['plan'] ?? []);
                    $statusLabels = ['PENDING' => $run ? 'Chờ xử lý' : 'Có thể áp dụng sau kiểm tra lại', 'COMPLETED' => 'Đã xử lý', 'SKIPPED' => 'Bỏ qua', 'FAILED' => 'Thất bại'];
                @endphp
                <tr>
                    <td><a href="{{ route('reconciliation-periods.show', $entry['period_id']) }}">{{ $entry['name'] }}</a></td>
                    <td>{{ $statusLabels[$entry['status']] ?? $entry['status'] }}</td>
                    <td>{{ $counts['repaired'] ?? 0 }}</td>
                    <td>{{ $counts['duplicates_consolidated'] ?? 0 }}</td>
                    <td>{{ $counts['normalized_unassigned'] ?? 0 }}</td>
                    <td>{{ $counts['removed'] ?? 0 }}</td>
                    <td>Protected: {{ $entry['protected_rows'] ?? $counts['protected'] ?? 0 }};
                        unresolved: {{ $counts['unresolved'] ?? 0 }}</td>
                    <td>
                        {{ $entry['reason'] ?? '' }}
                        @foreach ($counts['diagnostics']['reasons'] ?? [] as $reason => $count)
                            <div>{{ $reason }}: {{ $count }}</div>
                        @endforeach
                        @foreach ($counts['actions'] ?? [] as $action)
                            <div>Dòng {{ implode(' / ', $action['row_ids']) }} → giữ #{{ $action['survivor_row_id'] }}: {{ $action['reason'] }}</div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if (!$run && collect($periods)->contains(fn ($p) => $p['status'] === 'PENDING'))
        <form method="POST" action="{{ route('reconciliation-periods.repair-all.store') }}">
            @csrf
            <input type="hidden" name="preview_token" value="{{ $token }}">
            <button class="btn btn-warning" type="submit">Áp dụng các kỳ đã xem trước</button>
        </form>
    @endif
    <a class="btn btn-link" href="{{ route('reconciliation-periods.index') }}">Về danh sách kỳ</a>
</div>
@endsection
