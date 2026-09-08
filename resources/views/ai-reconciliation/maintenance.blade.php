@extends('layouts.app')
@section('content')
<div class="container py-4">
    <h1>Đối soát AI — Bảo trì</h1>
    <p>Đã dừng OCR nhật trình tuần và đối soát AI. Dữ liệu lịch sử được giữ nguyên.</p>
    <p>Giờ đối chiếu được lấy từ ảnh hằng ngày và có thể chỉnh sửa trực tiếp.</p>
    <a class="btn btn-primary" href="{{ route('reconciliation-periods.index') }}">Mở bảng đối chiếu</a>
</div>
@endsection
