@extends('layouts.app')

@section('content')
<div class="activity-page">
    <div class="activity-head">
        <div>
            <div class="activity-eyebrow">TRUY VẾT HỆ THỐNG</div>
            <h1>Nhật ký hoạt động</h1>
            <p>Theo dõi ai đã thực hiện thao tác gì, trên thiết bị nào và vào thời điểm nào.</p>
        </div>
        <div class="activity-count">{{ number_format($activities->total()) }} hoạt động</div>
    </div>

    <form method="GET" action="{{ route('activities.index') }}" class="activity-filters">
        <div class="activity-field activity-field-wide">
            <label>Tìm kiếm</label>
            <input name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Mã máy, số khung, biển số, người thao tác...">
        </div>

        <div class="activity-field">
            <label>Loại thao tác</label>
            <select name="event">
                <option value="">Tất cả</option>
                @foreach ($eventOptions as $event)
                    <option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>
                        {{ (new \App\Models\ActivityLog(['event' => $event]))->eventLabel() }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="activity-field">
            <label>Người thao tác</label>
            <select name="user_id">
                <option value="">Tất cả</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected((string)($filters['user_id'] ?? '') === (string)$user->id)>
                        {{ $user->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="activity-field">
            <label>Thiết bị</label>
            <select name="machine_id">
                <option value="">Tất cả</option>
                @foreach ($machines as $machine)
                    <option value="{{ $machine->id }}" @selected((string)($filters['machine_id'] ?? '') === (string)$machine->id)>
                        {{ $machine->asset_code }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="activity-field">
            <label>Từ ngày</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
        </div>

        <div class="activity-field">
            <label>Đến ngày</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
        </div>

        <div class="activity-actions">
            <button type="submit">Lọc dữ liệu</button>
            <a href="{{ route('activities.index') }}">Đặt lại</a>
        </div>
    </form>

    <section class="activity-card">
        @forelse ($activities as $activity)
            @php
                $changes = $activity->properties['new'] ?? [];
                $old = $activity->properties['old'] ?? [];
            @endphp

            <article class="activity-item">
                <div class="activity-line">
                    <span class="activity-dot"></span>
                </div>

                <div class="activity-icon">
                    {{ strtoupper(substr($activity->eventLabel(), 0, 1)) }}
                </div>

                <div class="activity-content">
                    <div class="activity-row">
                        <div>
                            <strong>{{ $activity->eventLabel() }}</strong>
                            <p>{{ $activity->description }}</p>
                        </div>
                        <time title="{{ $activity->occurred_at?->format('d/m/Y H:i:s') }}">
                            {{ $activity->occurred_at?->diffForHumans() }}
                        </time>
                    </div>

                    <div class="activity-meta">
                        <span>
                            Người thực hiện:
                            <b>{{ $activity->user?->name ?? 'Hệ thống' }}</b>
                        </span>

                        @if ($activity->machine)
                            <a href="{{ route('machines.show', $activity->machine) }}">
                                Thiết bị: <b>{{ $activity->machine->asset_code }}</b>
                            </a>
                        @endif

                        <span>{{ $activity->occurred_at?->format('H:i · d/m/Y') }}</span>
                    </div>

                    @if ($changes)
                        <details class="activity-details">
                            <summary>Xem {{ count($changes) }} trường đã thay đổi</summary>
                            <div class="activity-change-list">
                                @foreach ($changes as $field => $newValue)
                                    <div class="activity-change">
                                        <code>{{ $field }}</code>
                                        <span>{{ is_array($old[$field] ?? null) ? json_encode($old[$field], JSON_UNESCAPED_UNICODE) : ($old[$field] ?? '—') }}</span>
                                        <i>→</i>
                                        <b>{{ is_array($newValue) ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : ($newValue ?? '—') }}</b>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            </article>
        @empty
            <div class="activity-empty">
                <strong>Chưa có hoạt động phù hợp</strong>
                <span>Thử thay đổi bộ lọc hoặc thực hiện một thao tác mới trong hệ thống.</span>
            </div>
        @endforelse
    </section>

    <div class="activity-pagination">
        {{ $activities->links() }}
    </div>
</div>

<style>
.activity-page{padding-bottom:32px}.activity-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.activity-eyebrow{color:#64748b;font-size:12px;font-weight:800;letter-spacing:.08em}.activity-head h1{margin:6px 0 0;color:#0f172a;font-size:30px;font-weight:800;letter-spacing:-.03em}.activity-head p{margin:7px 0 0;color:#64748b}.activity-count{padding:9px 13px;border:1px solid #dbe3ee;border-radius:10px;background:#fff;color:#475569;font-size:12px;font-weight:800}.activity-filters{display:grid;grid-template-columns:2fr repeat(2,1fr);gap:13px;margin-top:22px;padding:17px;border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.04)}.activity-field{display:flex;min-width:0;flex-direction:column;gap:6px}.activity-field label{color:#475569;font-size:11px;font-weight:800}.activity-field input,.activity-field select{width:100%;height:40px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;padding:0 11px;color:#0f172a;outline:0}.activity-field input:focus,.activity-field select:focus{border-color:#8bb2ff;box-shadow:0 0 0 3px rgba(37,99,235,.1)}.activity-actions{display:flex;align-items:flex-end;gap:8px}.activity-actions button,.activity-actions a{display:inline-flex;height:40px;align-items:center;justify-content:center;border-radius:10px;padding:0 14px;font-size:12px;font-weight:800;text-decoration:none}.activity-actions button{border:0;background:#2563eb;color:#fff}.activity-actions a{border:1px solid #cbd5e1;background:#fff;color:#475569}.activity-card{margin-top:18px;overflow:hidden;border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.04)}.activity-item{display:grid;grid-template-columns:18px 42px minmax(0,1fr);gap:12px;padding:18px;border-bottom:1px solid #eef2f7}.activity-item:last-child{border-bottom:0}.activity-line{position:relative;display:flex;justify-content:center}.activity-line:after{position:absolute;top:17px;bottom:-35px;width:1px;background:#dbe3ee;content:""}.activity-item:last-child .activity-line:after{display:none}.activity-dot{position:relative;z-index:1;width:9px;height:9px;margin-top:11px;border:2px solid #fff;border-radius:50%;background:#2563eb;box-shadow:0 0 0 2px #bfdbfe}.activity-icon{display:flex;width:40px;height:40px;align-items:center;justify-content:center;border-radius:11px;background:#e8efff;color:#2558c7;font-weight:800}.activity-content{min-width:0}.activity-row{display:flex;align-items:flex-start;justify-content:space-between;gap:15px}.activity-row strong{color:#0f172a;font-size:14px}.activity-row p{margin:3px 0 0;color:#475569;font-size:13px}.activity-row time{color:#94a3b8;font-size:11px;white-space:nowrap}.activity-meta{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:9px;color:#64748b;font-size:11px}.activity-meta a{color:#2563eb;text-decoration:none}.activity-details{margin-top:10px}.activity-details summary{cursor:pointer;color:#2563eb;font-size:11px;font-weight:800}.activity-change-list{display:flex;flex-direction:column;gap:6px;margin-top:9px;padding:10px;border-radius:10px;background:#f8fafc}.activity-change{display:grid;grid-template-columns:minmax(100px,.7fr) 1fr 20px 1fr;align-items:center;gap:8px;color:#64748b;font-size:11px}.activity-change code{color:#334155;font-weight:800}.activity-change i{text-align:center}.activity-change b{color:#0f172a}.activity-empty{display:flex;flex-direction:column;gap:5px;padding:48px;color:#94a3b8;text-align:center}.activity-empty strong{color:#475569}.activity-pagination{margin-top:17px}@media(max-width:1100px){.activity-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.activity-head{flex-direction:column}.activity-filters{grid-template-columns:1fr}.activity-actions{align-items:center}.activity-item{grid-template-columns:12px 36px minmax(0,1fr);padding:14px;gap:9px}.activity-icon{width:34px;height:34px}.activity-row{flex-direction:column;gap:4px}.activity-change{grid-template-columns:1fr}.activity-change i{display:none}}
</style>
@endsection
