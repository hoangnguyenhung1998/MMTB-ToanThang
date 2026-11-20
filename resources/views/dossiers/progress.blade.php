<div class="scroll" data-dossier-progress="{{ $progressUrl }}" data-active="{{ (int) $progress->contains('pending',true) }}" @if($progress->isEmpty()) hidden @endif>
    <table><thead><tr><th>File PDF</th><th>Trạng thái</th><th>Thời gian</th><th>Thao tác</th></tr></thead>
    <tbody>@include('dossiers.progress-rows')</tbody></table>
</div>
