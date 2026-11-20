@extends('layouts.app')
@section('content')
<main class="dossier">
@include('dossiers.common')
<header class="d-header"><h1>Đợt nhập PDF</h1><time>{{ $batch->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}</time></header>
<section class="d-panel">
<form method="post" action="{{ route('dossiers.upload',$batch) }}" enctype="multipart/form-data" data-dossier-upload>@csrf
<label>Chọn các PDF <input type="file" name="file" multiple accept="application/pdf,.pdf" required></label>
<button type="submit">Tải PDF</button>
<small>Tối đa {{ config('dossiers.max_upload_kb') / 1024 }} MB mỗi file. Giữ trang mở đến khi hiện Đã nhận PDF.</small>
<div data-upload-status role="status"></div>
</form>
</section>
@include('dossiers.progress',['progressUrl'=>route('dossiers.batch',[$batch,'progress'=>1,'files_page'=>$sources->currentPage()])])
{{ $sources->links() }}
</main>
@endsection
