@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
<header class="d-header">
<div><h1>{{ $machine->asset_code }}</h1><small>{{ $machine->machine_type }}</small></div>
<div class="d-actions"><a class="d-button secondary" href="#dossier-history">Lịch sử</a>
<form method="post" action="{{ route('dossiers.exports.print',$machine) }}">@csrf
<input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}"><button aria-label="Xuất PDF để in">Xuất PDF</button>
</form></div>
</header>
@php
    $notices = collect(['missing'=>'giấy bắt buộc còn thiếu','expired'=>'giấy hết hạn'])->map(fn ($label,$status)=>[$rows->where('status',$status)->count(), $label]);
    $notices->push([$progress->where('replacing',true)->count(),'bản thay đang chờ']);
@endphp
@if($notices->contains(fn ($notice)=>$notice[0]))
<p class="d-alert" role="status">{{ $notices->filter(fn ($notice)=>$notice[0])->map(fn ($notice)=>implode(' ',$notice))->implode(' · ') }}</p>
@endif
<section class="d-panel">
<div class="d-toolbar"><h2>Giấy tờ máy/xe</h2><a class="d-button secondary" href="{{ route('dossiers.machine.documents.add',$machine) }}">Thêm giấy tờ</a></div>
@include('dossiers.rows',['rows'=>$rows->whereIn('subject',['machine','assignment']),'group'=>$machine->dossier_group,'subjectKey'=>'machine:'.$machine->id])
</section>
<section class="d-panel">
<div class="d-toolbar"><h2>Tài xế</h2><a class="d-button secondary" href="{{ route('daily-photos.settings',['machine_id'=>$machine->id]) }}">Đổi tài xế</a></div>
@php($ids=app(\App\Services\DossierZaloRoster::class)->identities()->filter(fn($ids)=>$ids->count()===1))
@forelse($drivers as $history)
<details class="d-driver" @if($loop->first) open @endif>
<summary>{{ $history->driver->name }} @php($senders=$ids->filter(fn($rows)=>$rows->contains($history->driver_id))->keys()) @if($senders->isNotEmpty()) · Zalo {{ $senders->implode(', ') }} @endif</summary>
<div class="d-actions"><a href="{{ route('dossiers.driver',$history->driver) }}">Mở hồ sơ tài xế</a><a class="d-button secondary" href="{{ route('dossiers.driver.documents.add',$history->driver) }}">Thêm giấy tờ</a></div>
@include('dossiers.rows',['rows'=>$rows->where('subject','driver')->where('subject_id',$history->driver_id),'group'=>'driver','subjectKey'=>'driver:'.$history->driver_id])
</details>
@empty<p>Chưa gắn tài xế.</p>@endforelse
</section>
@include('dossiers.progress',['progressUrl'=>route('dossiers.machine',[$machine,'progress'=>1])])
<details class="d-panel"><summary>Tùy chọn xuất</summary>
<form method="post" action="{{ route('dossiers.exports.create',$machine) }}">@csrf<input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}"><input type="hidden" name="action" value="preview">
<p>Chọn tài xế và giấy. Sắp xếp thứ tự ở bước xem trước.</p>
@foreach($drivers as $h)<label><input type="checkbox" name="driver_ids[]" value="{{ $h->driver_id }}" checked> {{ $h->driver->name }}</label>@endforeach
@foreach($rows->filter(fn($r)=>$r['version']?->confirmed && !$r['version']->archived && $r['status']!=='future')->sortBy(fn($r)=>$r['type']->sort_order)->unique(fn($r)=>$r['version']->id) as $r)
<div><label><input type="checkbox" name="version_ids[]" value="{{ $r['version']->id }}" checked> {{ $r['type']->name }} · {{ $r['subject_name'] }} · {{ $r['label'] }}</label></div>
@endforeach
<button>Xem trước và sắp xếp</button></form>
@foreach($exports as $e)<p><a href="{{ route('dossiers.export',$e) }}">{{ $e->created_at }} · {{ $e->status }}</a></p>@endforeach
</details>
<details class="d-panel" id="dossier-history"><summary>Lịch sử</summary>@include('dossiers.history')
@foreach($exports as $e)<p><a href="{{ route('dossiers.export',$e) }}">PDF · {{ $e->created_at }} · {{ $e->status }}</a></p>@endforeach
</details>
<details class="d-panel"><summary>Cài đặt máy và phân công cũ</summary>
<p><a href="{{ route('machines.show',$machine) }}">Thông tin máy / giấy tờ cũ</a></p>
<form class="box" method="post" action="{{ route('dossiers.group',$machine) }}">@csrf<label>Nhóm hồ sơ <select name="dossier_group"><option value="machine" @selected($machine->dossier_group==='machine')>Máy</option><option value="vehicle" @selected($machine->dossier_group==='vehicle')>Xe</option></select></label><button>Lưu nhóm</button></form>
<form class="box" method="post" action="{{ route('dossiers.assign',$machine) }}">@csrf<label>Tài xế <select name="driver_id">@foreach($allDrivers as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label><label>Bắt đầu <input type="datetime-local" name="started_at" required></label><label>Kết thúc <input type="datetime-local" name="ended_at"></label><button>Thêm phân công cũ</button></form>
@foreach($histories as $h)<div class="box">{{ $h->driver?->name }} · {{ $h->started_at }} → {{ $h->ended_at ?: 'Đang mở' }}
@if(!$h->ended_at)<form method="post" action="{{ route('dossiers.assign.close',$h) }}">@csrf<label>Kết thúc riêng người này <input type="datetime-local" name="ended_at" required></label><button>Kết thúc</button></form>@endif</div>@endforeach
</details>
</main>
<script>document.querySelector('a[href="#dossier-history"]')?.addEventListener('click',()=>document.getElementById('dossier-history').open=true);</script>
@endsection
