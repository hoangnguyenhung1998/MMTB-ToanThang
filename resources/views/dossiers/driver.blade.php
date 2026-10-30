@extends('layouts.app')
@section('content')
<main class="dossier">@include('dossiers.common')<h1>Hồ sơ tài xế {{ $driver->name }}</h1>
<p><a href="{{ route('drivers.show',$driver) }}">Thông tin tài xế / giấy tờ cũ</a>. Giấy cá nhân được tái sử dụng khi chuyển máy.</p>
@include('dossiers.rows')
<h2>Người gửi Zalo liên kết</h2><p>Chọn sender ID đã được Collector ghi nhận. Không ghép theo tên; không thay máy fallback, phân công hay xử lý lại ảnh.</p>
@forelse($links as $link)<div class="box">Sender ID: {{ $link->sender_id }}<form method="post" action="{{ route('dossiers.sender.unlink',$link) }}">@csrf<label>Lý do bỏ liên kết <input name="reason" required></label><button>Bỏ liên kết hồ sơ</button></form></div>@empty<p>Chưa liên kết Zalo; tài xế vẫn có hồ sơ bình thường.</p>@endforelse
<form class="box" method="post" action="{{ route('dossiers.sender',$driver) }}">@csrf<label>Sender ID <select name="sender_id" required><option value="">Chọn người gửi</option>@foreach($senders as $s)<option value="{{ $s->sender_id }}">{{ $s->sender_id }}</option>@endforeach</select></label><button>Xác nhận sender thuộc tài xế này</button></form>
<h2>Lịch sử phân công</h2>@foreach($histories as $h)<p><a href="{{ route('dossiers.machine',$h->machine_id) }}">{{ $h->machine?->asset_code }}</a> / {{ $h->started_at }} → {{ $h->ended_at ?: 'Đang mở' }}</p>@endforeach
@include('dossiers.history')
</main>
@endsection
