<header class="d-header">
    <h1>Hồ sơ điện tử</h1>
    <details class="d-upload-menu"><summary class="d-button">Nhập PDF</summary>
        <form class="box" method="post" action="{{ route('dossiers.batches.create') }}">@csrf
            <input type="hidden" name="auto_analyze" value="0">
            <label><input type="checkbox" name="auto_analyze" value="1"> Phân loại / OCR sau khi chuẩn bị PDF</label>
            <button>Tạo đợt upload PDF</button>
        </form>
    </details>
</header>
<nav class="d-tabs" aria-label="Danh sách hồ sơ">
    @foreach(['machines'=>'Máy/xe','drivers'=>'Tài xế','batches'=>'Đợt nhập PDF'] as $key=>$label)
        <a href="{{ route('dossiers.index', $key === 'machines' ? [] : ['tab'=>$key]) }}" @if(($activeTab ?? 'machines') === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
