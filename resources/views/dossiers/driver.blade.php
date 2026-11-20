@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
<header class="d-header"><div><h1>Hồ sơ tài xế {{ $driver->name }}</h1>
@if($identity)<p>{{ $identity['person_name'] }} · CCCD {{ $identity['identity'] }} @if(!empty($identity['birth_date'])) · {{ $identity['birth_date'] }} @endif</p>@endif
</div><div class="d-actions"><a class="d-button secondary" href="#dossier-history">Lịch sử</a><a class="d-button" href="{{ route('dossiers.driver.documents.add',$driver) }}">Thêm giấy tờ</a></div></header>
<section class="d-panel"><h2>Giấy tờ tài xế</h2>
@include('dossiers.rows',['group'=>'driver','subjectKey'=>'driver:'.$driver->id])
</section>
@include('dossiers.progress',['progressUrl'=>route('dossiers.driver',[$driver,'progress'=>1])])
<details class="d-panel"><summary>Liên kết Zalo</summary>
<p><a href="{{ route('daily-photos.settings',['sender_id'=>$senderIds->first()]) }}">Ánh xạ check-in</a></p>
@forelse($senderIds as $sender)<p>{{ $sender }} · {{ $senderNames->firstWhere('sender_id',$sender)?->sender_name ?? 'Chưa có tên' }}</p>
@empty<p>Chưa liên kết Zalo.</p>@endforelse
@foreach($senderNames as $name)<small>{{ $name->sender_id }} · {{ $name->sender_name }} · {{ $name->received_at }}</small>@endforeach
</details>
<details id="dossier-history" class="d-panel"><summary>Lịch sử</summary>
@include('dossiers.history')
@foreach($histories as $h)<p><a href="{{ route('dossiers.machine',$h->machine_id) }}">{{ $h->machine?->asset_code }}</a> · {{ $h->started_at }} → {{ $h->ended_at ?: 'Đang mở' }}</p>@endforeach
<p><a href="{{ route('drivers.show',$driver) }}">Thông tin tài xế / giấy tờ cũ</a></p>
</details>
</main>
<script>document.querySelector('a[href="#dossier-history"]')?.addEventListener('click',()=>document.getElementById('dossier-history').open=true);</script>
@endsection
