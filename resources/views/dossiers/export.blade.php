@extends('layouts.app')
@section('content')
<main class="dossier">@include('dossiers.common')<h1>Bộ PDF {{ $export->machine->asset_code }}</h1><p>{{ $export->status }} · {{ $export->created_at }} · {{ $export->error }}</p>
@if($export->status==='ready')<p><a href="{{ route('dossiers.export.file',$export) }}">Tải bộ PDF</a></p><small>SHA256: {{ $export->sha256 }}</small>@else<p><a href="{{ route('dossiers.export',$export) }}">Cập nhật tiến độ</a>. Có thể đóng trang rồi mở lại.</p><form method="post" action="{{ route('dossiers.export.retry',$export) }}">@csrf<button>Retry cùng manifest</button></form>@endif
<h2>Manifest phiên bản (theo thứ tự đã yêu cầu)</h2>@foreach($export->manifest as $item)<div class="box">#{{ $item['version_id'] }} — {{ $item['type'] }} / {{ $item['subject_name'] }} · {{ $item['number'] }} · {{ $item['expiry_value'] }}<p>Trang thực xuất: @forelse($item['pages'] as $page){{ $page['page'] }} ({{ $page['rotation'] }}°) @emptyĐã dùng chung ở giấy trước@endforelse</p></div>@endforeach
<h2>Cảnh báo lúc xuất</h2>@foreach($export->warnings as $w)<p>{{ $w }}</p>@endforeach
</main>
@endsection
