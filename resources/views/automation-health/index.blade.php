@extends('layouts.app')

@section('content')
<meta http-equiv="refresh" content="30">
@php
    $labels = ['HEALTHY' => 'Hoạt động', 'DEGRADED' => 'Chập chờn', 'OFFLINE' => 'Mất kết nối', 'PAUSED' => 'Tạm dừng'];
    $colors = ['HEALTHY' => '#059669', 'DEGRADED' => '#d97706', 'OFFLINE' => '#dc2626', 'PAUSED' => '#64748b'];
@endphp
<div style="max-width:1500px;margin:0 auto;padding:28px 24px 48px">
    <div style="display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:22px">
        <div>
            <h1 style="font-size:27px;font-weight:750;color:#0f172a;margin:0 0 5px">Giám sát tiến trình tự động</h1>
            <p style="color:#64748b;margin:0">Laptop 24/24, worker và OpenClaw · tự làm mới mỗi 30 giây</p>
        </div>
        <div style="font-size:13px;color:#64748b;background:#fff;border:1px solid #dbe4f0;border-radius:10px;padding:10px 14px">
            Cập nhật {{ now('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') }}
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:20px">
        @foreach($summary as $status => $total)
            <div style="background:#fff;border:1px solid #dbe4f0;border-top:4px solid {{ $colors[$status] }};border-radius:14px;padding:16px 18px">
                <div style="font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase">{{ $labels[$status] }}</div>
                <div style="font-size:28px;font-weight:800;color:#0f172a;margin-top:4px">{{ $total }}</div>
            </div>
        @endforeach
    </div>

    @forelse($nodes as $node)
        <section style="background:#fff;border:1px solid #dbe4f0;border-radius:16px;margin-bottom:18px;overflow:hidden">
            <div style="display:flex;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid #e5eaf2">
                <div>
                    <strong style="font-size:17px;color:#0f172a">{{ $node->name }}</strong>
                    <span style="color:#64748b;margin-left:8px">{{ $node->location ?: $node->node_key }}</span>
                </div>
                <div style="font-size:13px;color:#64748b">
                    Node heartbeat:
                    <strong>{{ $node->last_heartbeat_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') ?? 'Chưa nhận' }}</strong>
                </div>
            </div>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;min-width:1050px">
                    <thead><tr style="background:#f8fafc;color:#475569;font-size:12px;text-align:left">
                        <th style="padding:12px 16px">DỊCH VỤ</th><th style="padding:12px">TRẠNG THÁI</th>
                        <th style="padding:12px">HEARTBEAT CUỐI</th><th style="padding:12px">API/LOOP CUỐI</th><th style="padding:12px">JOB THÀNH CÔNG</th>
                        <th style="padding:12px">JOB HIỆN TẠI</th><th style="padding:12px">HÀNG ĐỢI</th>
                        <th style="padding:12px">LỖI LIÊN TIẾP</th><th style="padding:12px">LỖI GẦN NHẤT</th><th style="padding:12px 16px">ĐIỀU KHIỂN</th>
                    </tr></thead>
                    <tbody>
                    @forelse($node->services as $service)
                        <tr style="border-top:1px solid #edf1f6;color:#1e293b;font-size:14px">
                            <td style="padding:14px 16px"><strong>{{ $service->name }}</strong><br><small style="color:#64748b">{{ $service->service_type }}{{ $service->version ? ' · v'.$service->version : '' }}</small></td>
                            <td style="padding:14px 12px"><span style="display:inline-block;background:{{ $colors[$service->effective_status] }}18;color:{{ $colors[$service->effective_status] }};border:1px solid {{ $colors[$service->effective_status] }}55;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800">{{ $labels[$service->effective_status] }}</span></td>
                            <td style="padding:14px 12px">{{ $service->last_heartbeat_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') ?? '—' }}</td>
                            <td style="padding:14px 12px">{{ $service->last_api_success_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') ?? '—' }}</td>
                            <td style="padding:14px 12px">{{ $service->last_job_success_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') ?? '—' }}</td>
                            <td style="padding:14px 12px">{{ $service->current_job ?: '—' }}</td>
                            <td style="padding:14px 12px">{{ $service->queue_depth ?? '—' }}</td>
                            <td style="padding:14px 12px">{{ $service->consecutive_errors }}</td>
                            <td style="padding:14px 12px;max-width:300px;color:{{ $service->last_error_message ? '#b91c1c' : '#64748b' }}">{{ $service->last_error_message ?: 'Không có lỗi' }}</td>
                            <td style="padding:14px 16px;min-width:260px"><form method="POST" action="{{ route('automation-health.commands.store', $service) }}" style="display:flex;gap:5px;flex-wrap:wrap">@csrf
                                @foreach(['HEALTH_CHECK' => 'Kiểm tra', 'RESTART' => 'Khởi động lại', 'PAUSE' => 'Tạm dừng', 'RETRY' => 'Chạy lại'] as $action => $label)
                                    <button name="action" value="{{ $action }}" type="submit" @if(in_array($action, ['RESTART','PAUSE'], true)) onclick="return confirm(@js('Xác nhận '.$label.' '.$service->name.'?'))" @endif style="border:1px solid #cbd5e1;background:#fff;border-radius:7px;padding:5px 8px;font-size:11px;cursor:pointer">{{ $label }}</button>
                                @endforeach
                            </form></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" style="padding:28px;text-align:center;color:#94a3b8">Node chưa gửi danh sách dịch vụ.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <div style="background:#fff;border:1px dashed #cbd5e1;border-radius:16px;padding:42px;text-align:center;color:#64748b">
            Chưa đăng ký node giám sát. Sau khi tạo node và cài agent trên laptop, dữ liệu sẽ xuất hiện tại đây.
        </div>
    @endforelse

    <section style="background:#fff;border:1px solid #dbe4f0;border-radius:16px;overflow:hidden;margin-top:20px">
        <div style="padding:16px 18px;border-bottom:1px solid #e5eaf2"><strong style="font-size:17px">30 sự cố gần nhất</strong></div>
        <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:800px">
            <thead><tr style="background:#f8fafc;color:#475569;font-size:12px;text-align:left"><th style="padding:12px 16px">BẮT ĐẦU</th><th style="padding:12px">NODE / DỊCH VỤ</th><th style="padding:12px">LOẠI</th><th style="padding:12px">NỘI DUNG</th><th style="padding:12px 16px">KẾT THÚC</th></tr></thead>
            <tbody>
            @forelse($incidents as $incident)
                <tr style="border-top:1px solid #edf1f6;font-size:14px">
                    <td style="padding:13px 16px">{{ $incident->started_at->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') }}</td>
                    <td style="padding:13px 12px"><strong>{{ $incident->service?->node?->name }}</strong> / {{ $incident->service?->name }}</td>
                    <td style="padding:13px 12px;color:{{ $colors[$incident->type] ?? '#64748b' }};font-weight:750">{{ $incident->type }}</td>
                    <td style="padding:13px 12px">{{ $incident->message }}</td>
                    <td style="padding:13px 16px">{{ $incident->resolved_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') ?? 'Đang mở' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="padding:28px;text-align:center;color:#94a3b8">Chưa có sự cố nào.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section style="background:#fff;border:1px solid #dbe4f0;border-radius:16px;overflow:hidden;margin-top:20px">
        <div style="padding:16px 18px;border-bottom:1px solid #e5eaf2"><strong style="font-size:17px">30 lệnh vận hành gần nhất</strong></div>
        <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:800px">
            <thead><tr style="background:#f8fafc;color:#475569;font-size:12px;text-align:left"><th style="padding:12px 16px">THỜI GIAN</th><th style="padding:12px">DỊCH VỤ</th><th style="padding:12px">LỆNH</th><th style="padding:12px">TRẠNG THÁI</th><th style="padding:12px">NGƯỜI GỬI</th><th style="padding:12px 16px">KẾT QUẢ</th></tr></thead>
            <tbody>@forelse($commands as $command)<tr style="border-top:1px solid #edf1f6;font-size:14px"><td style="padding:13px 16px">{{ $command->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i:s d/m/Y') }}</td><td style="padding:13px 12px">{{ $command->service?->name }}</td><td style="padding:13px 12px;font-weight:700">{{ $command->action }}</td><td style="padding:13px 12px">{{ $command->status }}</td><td style="padding:13px 12px">{{ $command->user?->name }}</td><td style="padding:13px 16px">{{ $command->error_message ?: data_get($command->result, 'message', '—') }}</td></tr>@empty<tr><td colspan="6" style="padding:28px;text-align:center;color:#94a3b8">Chưa có lệnh nào.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
</div>
@endsection
