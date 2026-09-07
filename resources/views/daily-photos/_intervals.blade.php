@inject('dailySync', 'App\Services\Reconciliation\DailyPhotoSyncService')
@php
    $photoSources = $dailySync->sources($reconciliationRow, true);
    $suggestedIntervals = old('intervals', $reconciliationRow->daily_intervals ?: $dailySync->preview($reconciliationRow)['intervals']);
    $kindLabels = ['regular_morning' => 'HC sáng', 'regular_afternoon' => 'HC chiều', 'overtime_lunch' => 'TC trưa', 'overtime_afternoon' => 'TC chiều', 'overtime_evening' => 'TC tối'];
@endphp
<section class="card mb-3"><div class="card-body">
<h2 class="h5">Ảnh hằng ngày và phân bổ giờ</h2>
<p>{{ $reconciliationRow->evidence_summary }}</p>
<div class="d-flex flex-wrap gap-2 mb-3">
@foreach($photoSources as $source)
<a href="{{ route('ocr-reviews.show', $source) }}" target="_blank" rel="noopener" class="text-center">
<img src="{{ route('ocr-reviews.image', $source) }}" alt="Ảnh #{{ $source->id }}" loading="lazy" style="width:100px;height:100px;object-fit:contain">
<div>#{{ $source->id }} · {{ $source->extracted_date->format('d/m') }} {{ substr($source->extracted_time,0,5) }}</div>
@if(data_get($source->daily_metadata, 'near_duplicate_ids'))<small>Ảnh có thể gần trùng</small>@endif
</a>
@endforeach
</div>
@if($canEdit && in_array($reconciliationPeriod->status, ['GENERATED','REVIEWING']))
<form method="POST" action="{{ route('daily-photos.allocate', $reconciliationRow) }}">
@csrf
<p class="small">Chọn ảnh vào/ra hoặc nhập giờ bổ sung. Giờ gốc không đổi. Giờ vào cho phép muộn 10 phút; giờ ra làm tròn xuống. Ca 4 tiếng chỉ là gợi ý để kiểm tra.</p>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Ca</th><th>Ảnh vào</th><th>Ảnh ra</th><th>Giờ vào bổ sung</th><th>Giờ ra bổ sung</th></tr></thead><tbody>
@foreach($kindLabels as $kind => $label)
@php $index = $loop->index; $suggestion = collect($suggestedIntervals)->firstWhere('kind', $kind); @endphp
<tr><td>{{ $label }}<input type="hidden" name="intervals[{{ $index }}][kind]" value="{{ $kind }}"></td>
@foreach(['start' => 'Vào', 'end' => 'Ra'] as $edge => $edgeLabel)
<td><select class="form-select form-select-sm" name="intervals[{{ $index }}][{{ $edge }}_job_id]" aria-label="{{ $label }} {{ $edgeLabel }}">
<option value="">Không chọn</option>@foreach($photoSources as $source)<option value="{{ $source->id }}" @selected(($suggestion[$edge.'_job_id'] ?? null) === $source->id)>#{{ $source->id }} · {{ $source->extracted_date->format('d/m') }} {{ substr($source->extracted_time,0,5) }}</option>@endforeach
</select></td>
@endforeach
@foreach(['start','end'] as $edge)<td><input class="form-control form-control-sm" type="text" inputmode="numeric" placeholder="HH:mm" pattern="(?:[01]\d|2[0-3]):[0-5]\d" name="intervals[{{ $index }}][{{ $edge }}]" value="{{ empty($suggestion[$edge.'_job_id']) ? ($suggestion[$edge] ?? '') : '' }}" aria-label="{{ $label }} {{ $edge }}" maxlength="5"></td>@endforeach
</tr>@endforeach
</tbody></table></div>
<label class="d-block mb-2">Lý do bổ sung khi thiếu ảnh <input class="form-control" name="manual_reason" value="{{ old('manual_reason') }}" maxlength="1000"></label>
<label class="d-block mb-2"><input type="checkbox" name="confirm_manual" value="1"> Tôi xác nhận giờ bổ sung là giờ làm thực tế.</label>
<button class="btn btn-primary">Làm tròn và phân bổ giờ</button>
</form>
@endif
</div></section>
