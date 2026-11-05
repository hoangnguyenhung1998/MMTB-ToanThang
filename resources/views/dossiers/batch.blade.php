@extends('layouts.app')
@section('content')
<main class="dossier">@include('dossiers.common')
<h1>Đợt nhập {{ $batch->created_at }}</h1><p>Mỗi file PDF mang mã máy. Tối đa {{ config('dossiers.max_upload_kb') / 1024 }} MB/file. Mỗi file được tải riêng; có thể thử lại file lỗi.</p>
<div class="box"><input type="file" id="files" multiple accept="application/pdf,.pdf" aria-label="Chọn các PDF"><button id="upload" type="button">Tải các file đã chọn</button><div id="results" role="status"></div></div>
<p><a href="{{ route('dossiers.batch', $batch) }}">Cập nhật tiến độ / mở lại đợt nhập</a></p>
<table><tr><th>File</th><th>Máy</th><th>Trạng thái</th><th>Trang</th></tr>@foreach($sources as $s)<tr><td><a href="{{ route('dossiers.source', $s) }}">{{ $s->original_name }}</a></td><td>{{ $s->machine?->asset_code ?: $s->resolution }}</td><td>{{ $s->status }} {{ $s->confirmed ? '— Đã xác nhận hồ sơ' : '' }}<br>{{ $s->error }}</td><td>{{ $s->progress }}/{{ $s->page_count ?: '?' }}</td></tr>@endforeach</table>
</main>
<script>
const button=document.getElementById('upload');
async function sendFile(file,row){row.replaceChildren(document.createTextNode(file.name+' — đang tải…'));const data=new FormData();data.append('file',file);
try{const r=await fetch(@json(route('dossiers.upload',$batch)),{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,'Accept':'application/json'},body:data});const result=await r.json();if(!r.ok)throw Error(result.message||'Upload lỗi');const a=document.createElement('a');a.href=result.url;a.textContent=file.name+(result.duplicate?' — file trùng đã tồn tại, mở bản gốc':' — đã lưu, mở phân loại');row.replaceChildren(a);}
catch(e){row.replaceChildren(document.createTextNode(file.name+' — '+e.message));const retry=document.createElement('button');retry.type='button';retry.textContent='Thử lại';retry.onclick=()=>sendFile(file,row);row.append(retry);}}
button.onclick=async()=>{button.disabled=true;for(const file of document.getElementById('files').files){const row=document.createElement('p');document.getElementById('results').append(row);await sendFile(file,row);}button.disabled=false;};
</script>
@endsection
