@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
<header class="d-header"><h1>Thêm giấy tờ</h1><strong>{{ $name }}</strong></header>
<form method="post" enctype="multipart/form-data" class="d-panel" data-dossier-upload>@csrf
<label>Loại giấy <select name="type_id" required data-existing-paper><option value="">Chọn loại giấy</option>@foreach($types as $type)<option value="{{ $type->id }}" @if($existing->has($type->id)) data-replace="{{ route('dossiers.versions.replace',$existing[$type->id]) }}" @endif>{{ $type->name }}</option>@endforeach</select></label>
<p><a data-replace-link hidden>Loại giấy đã có · Thay file hiện hành</a></p>
<label>File PDF <input name="file" type="file" accept="application/pdf,.pdf" required></label>
<label><input name="auto_analyze" type="checkbox" value="1"> Phân loại / OCR sau khi chuẩn bị PDF</label>
<button type="submit">Tải PDF</button>
<div data-upload-status role="status"></div>
</form>
</main>
@endsection
