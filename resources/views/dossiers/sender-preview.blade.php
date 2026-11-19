@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
<h1>Xác nhận người gửi Zalo</h1>
<p>{{ $senderName ?? 'Chưa có tên Zalo' }} · {{ $sender->sender_id }}</p>
<table>
<tr><th></th><th>Hiện tại</th><th>Sau xác nhận</th></tr>
<tr><th>Máy mặc định</th><td>{{ $current?->machine?->asset_code ?? 'Chưa gán' }}</td><td>{{ $machine->asset_code }}</td></tr>
<tr><th>Tài xế liên kết</th><td>{{ $link?->driver?->name ?? 'Chưa gán' }}</td><td>{{ app(\App\Services\Dossiers\Ai\DossierComparison::class)->identity($driver->id)['person_name'] ?? $driver->name }}</td></tr>
</table>
@if($selected)<p>Kết thúc riêng phân công #{{ $selected->id }} của {{ $selected->driver->name }} trên máy {{ $machine->asset_code }}.</p>@endif
<form method="post" action="{{ route('dossiers.sender.mapping',$driver) }}">@csrf
<input type="hidden" name="sender_id" value="{{ $sender->sender_id }}">
<input type="hidden" name="machine_id" value="{{ $machine->id }}">
<input type="hidden" name="replace_history_id" value="{{ $selected?->id }}">
<input type="hidden" name="receipt" value="{{ $receipt }}">
<button>Xác nhận mapping và phân công</button>
<a href="{{ route('dossiers.driver',$driver) }}">Quay lại</a>
</form>
</main>
@endsection
