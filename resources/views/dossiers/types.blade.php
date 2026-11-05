@extends('layouts.app')
@section('content')
<main class="dossier">@include('dossiers.common')<h1>Danh mục giấy tờ và cảnh báo</h1>
<p>Danh mục khởi đầu là gợi ý, chưa đặt loại nào bắt buộc. Người quản lý cấu hình theo nhu cầu đã xác nhận; không suy ra yêu cầu pháp lý.</p>
<p>Telegram hồ sơ: {{ config('dossiers.telegram_enabled') ? 'Đã bật cấu hình' : 'Mặc định tắt' }}; số người nhận: {{ count(config('dossiers.telegram_recipients',[])) }}. Người nhận để trống sẽ không gửi. Cấu hình môi trường xem runbook.</p>
@foreach($types->concat([null]) as $t)<form class="box" method="post" action="{{ route('dossiers.types.save',$t) }}">@csrf
<h2>{{ $t?->name ?: 'Thêm loại giấy' }}</h2><label>Tên <input name="name" value="{{ $t?->name }}" required maxlength="255"></label>
@foreach(['machine'=>'Máy','vehicle'=>'Xe','driver'=>'Tài xế'] as $key=>$label)<label><input type="checkbox" name="groups[]" value="{{ $key }}" @checked(in_array($key,$t?->groups ?? []))> Áp dụng {{ $label }}</label><label><input type="checkbox" name="required_groups[]" value="{{ $key }}" @checked(in_array($key,$t?->required_groups ?? []))> Bắt buộc {{ $label }}</label>@endforeach
<label>Chế độ gợi ý hạn <select name="expiry_mode">@foreach(['review'=>'Cần kiểm tra','expiring'=>'Có hạn','permanent'=>'Không thời hạn'] as $key=>$label)<option value="{{ $key }}" @selected($t?->expiry_mode===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Mốc nhắc (ngày) <input name="warning_days_text" value="{{ implode(',',$t?->warning_days ?? [30,15,7]) }}" required></label><label>Thứ tự PDF <input type="number" name="sort_order" min="0" max="10000" value="{{ $t?->sort_order ?? 0 }}" required></label><button>Lưu danh mục</button></form>@endforeach
</main>
@endsection
