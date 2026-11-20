@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
<h1>Thay {{ $version->record->type->name }}</h1>
<p>Giấy #{{ $version->id }} tiếp tục có hiệu lực cho đến khi bản mới được xác nhận.</p>
@if($pending)<p><a href="{{ route('dossiers.source',$pending) }}">Mở bản thay đang xử lý</a></p>
@else
<form method="post" enctype="multipart/form-data" action="{{ route('dossiers.versions.replace',$version) }}">@csrf
<label>PDF của giấy mới <input type="file" name="file" accept="application/pdf" required></label><button>Tải bản thay</button>
</form>
@endif
</main>
@endsection
