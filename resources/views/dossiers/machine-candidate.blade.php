<form class="machine-controls" method="post" action="{{ route('dossiers.ai.publish',$paper->run_id) }}">@csrf
<input type="hidden" name="proposal_id" value="{{ $paper->id }}">
@if(!empty($paper->data['driver_id']))<input type="hidden" name="driver_id" value="{{ $paper->data['driver_id'] }}">@endif
<a href="{{ route('dossiers.file',[$paper->run->source_id,'source']) }}" target="_blank" rel="noopener">Xem bản mới · Trang {{ implode(', ', $paper->data['pages']) }}</a>
<button name="activate" value="1">Đặt làm bản hiện hành</button>
</form>
