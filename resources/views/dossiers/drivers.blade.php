@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
@include('dossiers.home-tabs',['activeTab'=>'drivers'])
<div class="scroll d-table">
<table><thead><tr><th>Tài xế</th><th>ID / tên Zalo</th><th>Máy theo check-in</th></tr></thead><tbody>
@foreach($drivers as $driver)
<tr><td><a href="{{ route('dossiers.driver',$driver) }}">{{ $driver->name }}</a></td>
<td>@foreach($links->filter(fn ($ids) => $ids->contains($driver->id))->keys() as $sender)
<div>{{ $sender }} · {{ $names[$sender] ?? 'Chưa có tên' }}</div>@endforeach</td>
<td>{{ $machines[$roster[$driver->id]->machine_id ?? 0] ?? '—' }}</td></tr>
@endforeach
</tbody></table></div>
{{ $drivers->links() }}
</main>
@endsection
