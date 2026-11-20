@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
<header class="d-header"><h1>Thay file · {{ $version->record->type->name }}</h1></header>
<p>Giấy #{{ $version->id }} vẫn dùng được trong lúc bản thay đang xử lý.</p>
@if($pending)<p><a href="{{ route('dossiers.source',$pending) }}">Mở bản thay đang xử lý</a></p>
@else
<form method="post" enctype="multipart/form-data" action="{{ route('dossiers.versions.replace',$version) }}" class="d-panel" data-dossier-upload>@csrf
<label>PDF của giấy mới <input type="file" name="file" accept="application/pdf,.pdf" required></label>
<button type="submit">Tải bản thay</button><div data-upload-status role="status"></div>
</form>
@endif
</main>
@endsection
