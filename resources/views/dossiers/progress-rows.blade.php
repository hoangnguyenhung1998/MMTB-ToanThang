@foreach($progress as $row)
<tr data-progress-key="{{ $row['row_key'] }}" data-progress-label="{{ $row['label'] }}{{ $row['total'] ? ' · '.$row['count'].'/'.$row['total'].' trang' : '' }}" data-progress-link="{{ $row['url'] }}{{ $row['review'] ? '#ocr-review' : '' }}">
    <td><a href="{{ $row['url'] }}">{{ $row['name'] }}</a><small>Đã nhận PDF <time datetime="{{ $row['received']->toIso8601String() }}">{{ $row['received']->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s') }}</time></small></td>
    <td><strong>{{ $row['label'] }}</strong>
        @if($row['total'])<small>{{ $row['count'] }}/{{ $row['total'] }} trang · {{ floor(100 * $row['count'] / $row['total']) }}%</small>
        @elseif($row['count'])<small>{{ $row['count'] }} trang đã chuẩn bị</small>@endif
        @if($row['stale'])<small class="d-warning">Chưa nhận cập nhật từ worker</small>@endif
        @if($row['error'])<small class="d-danger">{{ $row['error'] }}</small>@endif
    </td>
    <td><span>Chờ {{ gmdate('H:i:s',$row['wait']) }}</span>
        @if($row['run'] !== null)<small>Chạy {{ gmdate('H:i:s',$row['run']) }}</small>@endif
        <small>Cập nhật {{ $row['updated']->timezone('Asia/Ho_Chi_Minh')->format('d/m H:i:s') }}</small>
        @if($row['attempt'] > 0)<small>Lần {{ $row['attempt'] }} của tác vụ</small>@endif
        @if($row['history'])<details><summary>Các lần chạy</summary>@foreach($row['history'] as $attempt)<small>{{ \Illuminate\Support\Carbon::parse($attempt['started_at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m H:i:s') }} · {{ ['expired'=>'Hết phiên worker','succeeded'=>'Hoàn tất','failed'=>'Lỗi'][$attempt['outcome']] }} · {{ gmdate('H:i:s',max(0,(int) \Illuminate\Support\Carbon::parse($attempt['started_at'])->diffInSeconds(\Illuminate\Support\Carbon::parse($attempt['finished_at']),false))) }}</small>@endforeach</details>@endif
    </td>
    <td>
        @if($row['review'])<strong>{{ $row['review'] }} cần xác nhận</strong><br><a href="{{ $row['url'] }}#ocr-review">Mở duyệt</a>
        @elseif($row['label'] === 'Hoàn tất')<a href="{{ $row['url'] }}">Mở file</a>@endif
        @if($row['retry'])<form action="{{ $row['retry'] }}" method="post">@csrf<button class="secondary">Thử lại</button></form>
        @elseif($row['label'] === 'Lỗi')<a href="{{ $row['url'] }}#ocr-review">Mở file để thử lại</a>@endif
    </td>
</tr>
@endforeach
