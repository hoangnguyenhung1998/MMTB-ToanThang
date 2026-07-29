@extends('layouts.app')

@section('content')
    @php
        use Illuminate\Support\Facades\Storage;
        use Illuminate\Support\Str;
        $proofUrl = $event->proof_file_path ? Storage::disk('public')->url($event->proof_file_path) : null;
        $isImage = $event->proof_file_path ? Str::of($event->proof_file_path)->lower()->endsWith(['.jpg', '.jpeg', '.png', '.webp', '.gif']) : false;
    @endphp

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Sửa/Bổ sung biên bản: {{ $machine->asset_code }}</h1>
            <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Quay lại</a>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <p><strong>Loại sự kiện:</strong> {{ $event->type }}</p>
                <p><strong>Thời gian hiện tại:</strong> {{ $event->occurred_at }}</p>

                @if ($proofUrl)
                    <div class="mb-3">
                        <p class="mb-1"><strong>File hiện tại:</strong></p>
                        @if ($isImage)
                            <img src="{{ $proofUrl }}" alt="proof" style="max-height: 180px;" class="mb-2 d-block">
                            <a class="btn btn-sm btn-outline-primary" href="{{ $proofUrl }}" target="_blank">Xem ảnh</a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ $proofUrl }}" download>Lưu ảnh</a>
                        @else
                            <a class="btn btn-sm btn-outline-secondary" href="{{ $proofUrl }}" download>Tải file hiện tại</a>
                        @endif
                    </div>
                @else
                    <p class="text-danger">Hiện chưa có file biên bản.</p>
                @endif

                <form method="POST" action="{{ route('machine-events.update-proof', [$machine, $event]) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label">Tải lên file biên bản mới</label>
                        <input class="form-control" type="file" name="proof_file">
                        <div class="form-text">Nếu không chọn file thì giữ file hiện tại.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Thời gian sự kiện</label>
                        <input class="form-control" type="datetime-local" name="occurred_at" value="{{ old('occurred_at', optional($event->occurred_at)->format('Y-m-d\TH:i')) }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ghi chú</label>
                        <textarea class="form-control" name="note" rows="3">{{ old('note', $event->note) }}</textarea>
                    </div>

                    <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
                </form>
            </div>
        </div>
    </div>
@endsection
