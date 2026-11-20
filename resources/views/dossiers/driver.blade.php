@extends('layouts.app')
@section('content')
<main class="dossier">@include('dossiers.common')<h1>Hồ sơ tài xế {{ $driver->name }}</h1>
<p><a href="{{ route('dossiers.drivers') }}">Tất cả tài xế</a></p>
@if($identity)<p>{{ $identity['person_name'] }} · CCCD {{ $identity['identity'] }}</p>@endif
<details><summary>Tên Zalo đã ghi nhận</summary>@foreach($senderNames as $name)<div>{{ $name->sender_id }} · {{ $name->sender_name }} · {{ $name->received_at }}</div>@endforeach</details>
<p><a href="{{ route('drivers.show',$driver) }}">Thông tin tài xế / giấy tờ cũ</a>. Giấy cá nhân được tái sử dụng khi chuyển máy.</p>
@include('dossiers.rows')
<h2>Ánh xạ check-in</h2>
<p><a href="{{ route('daily-photos.settings',['sender_id'=>$senderIds->first()]) }}">Đổi máy hoặc liên kết ID Zalo trong cấu hình check-in</a></p>
@forelse($senderIds as $sender)<p>{{ $sender }} · {{ $senderNames->firstWhere('sender_id',$sender)?->sender_name ?? 'Chưa có tên' }}</p>
@empty<p>Chưa liên kết Zalo.</p>@endforelse
<h2>Lịch sử phân công</h2>@foreach($histories as $h)<p><a href="{{ route('dossiers.machine',$h->machine_id) }}">{{ $h->machine?->asset_code }}</a> / {{ $h->started_at }} → {{ $h->ended_at ?: 'Đang mở' }}</p>@endforeach
@include('dossiers.history')
</main>
@endsection
