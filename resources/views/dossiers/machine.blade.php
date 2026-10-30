@extends('layouts.app')
@section('content')
<main class="dossier">@include('dossiers.common')
<h1>{{ $machine->asset_code }} — {{ $machine->machine_type }}</h1>
<p><a href="{{ route('machines.show',$machine) }}">Thông tin máy / giấy tờ cũ</a>. Giấy tờ cũ chưa được tự xác nhận vào hồ sơ điện tử.</p>
<form class="box" method="post" action="{{ route('dossiers.group',$machine) }}">@csrf<label>Nhóm hồ sơ <select name="dossier_group"><option value="machine" @selected($machine->dossier_group==='machine')>Máy</option><option value="vehicle" @selected($machine->dossier_group==='vehicle')>Xe</option></select></label><button>Lưu nhóm</button></form>
@include('dossiers.rows')
<h2>Xuất bộ PDF thống nhất</h2>
<form class="box" method="post" action="{{ route('dossiers.exports.create',$machine) }}">@csrf<input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}"><input type="hidden" name="action" value="preview">
<p>Chọn một/nhiều tài xế hiện tại. Đặt thứ tự giấy ở bước xem trước.</p>
@foreach($drivers as $h)<label><input type="checkbox" name="driver_ids[]" value="{{ $h->driver_id }}" checked> {{ $h->driver->name }} <a href="{{ route('dossiers.driver',$h->driver) }}">Hồ sơ</a></label>@endforeach
<div>@foreach($rows->filter(fn($r)=>$r['version'])->sortBy(fn($r)=>$r['type']->sort_order)->unique(fn($r)=>$r['version']->id) as $r)<label style="display:block"><input type="checkbox" name="version_ids[]" value="{{ $r['version']->id }}" @checked(in_array($r['status'],['valid','permanent','expiring'],true))> #{{ $r['version']->id }} {{ $r['type']->name }} / {{ $r['subject_name'] }} — {{ $r['label'] }}</label>@endforeach</div><button>Xem trước và sắp xếp</button></form>
<h2>Bộ đã yêu cầu xuất của bạn</h2>@foreach($exports as $e)<p><a href="{{ route('dossiers.export',$e) }}">{{ $e->created_at }} — {{ $e->status }}</a></p>@endforeach
<h2>Phân công nhiều tài xế</h2><p>Thêm/kết thúc từng người trên lịch sử hiện có. Thao tác này giữ tài xế khác; “đổi tài xế chính” ở màn hình cũ vẫn là luồng riêng.</p>
<form class="box" method="post" action="{{ route('dossiers.assign',$machine) }}">@csrf<label>Tài xế <select name="driver_id">@foreach($allDrivers as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label><label>Bắt đầu <input type="datetime-local" name="started_at" required></label><label>Kết thúc (tùy chọn) <input type="datetime-local" name="ended_at"></label><button>Thêm phân công</button></form>
@foreach($histories as $h)<div class="box">{{ $h->driver?->name }} / {{ $h->started_at }} → {{ $h->ended_at ?: 'Đang mở' }}
@if(!$h->ended_at)<form method="post" action="{{ route('dossiers.assign.close',$h) }}">@csrf<label>Kết thúc riêng người này <input type="datetime-local" name="ended_at" required></label><button>Kết thúc</button></form>@endif</div>@endforeach
@include('dossiers.history')
</main>
@endsection
