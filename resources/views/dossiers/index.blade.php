@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
<h1>Hồ sơ điện tử máy / xe</h1>
<p>Các trạng thái được hiển thị đồng thời; giấy thiếu hoặc hết hạn luôn có số riêng.</p>
<form method="get" class="box"><label>Mã máy <input name="q" value="{{ request('q') }}"></label><label>Trạng thái <select name="status"><option value="">Tất cả</option>@foreach($labels as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }} ({{ $totals[$key] }})</option>@endforeach</select></label><button>Lọc</button></form>
<form method="post" action="{{ route('dossiers.batches.create') }}">@csrf<button>Tạo đợt upload PDF</button></form>
<div class="scroll box"><table><thead><tr><th>Máy / xe</th><th>BCH / công trình hiện tại</th><th>Tài xế hiện tại</th><th>Giấy tờ theo trạng thái</th></tr></thead><tbody>
@foreach($items as $item)<tr><td><a href="{{ route('dossiers.machine', $item['machine']) }}">{{ $item['machine']->asset_code }}</a><br>{{ $item['machine']->machine_type }}</td><td>@foreach($item['assignments'] as $a){{ $a->commandCenter?->name }} / {{ $a->project?->name }}<br>@endforeach</td><td>@foreach($item['drivers'] as $h)<a href="{{ route('dossiers.driver', $h->driver) }}">{{ $h->driver->name }}</a><br>@endforeach</td><td>@foreach($item['counts'] as $key => $count)<span class="badge {{ $key }}">{{ $labels[$key] }}: {{ $count }}</span> @endforeach</td></tr>@endforeach
</tbody></table></div>
<h2>Đợt nhập gần đây</h2>@foreach($batches as $batch)<p><a href="{{ route('dossiers.batch', $batch) }}">{{ $batch->created_at }} — {{ $batch->id }}</a></p>@endforeach
</main>
@endsection
