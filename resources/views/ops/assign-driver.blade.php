@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <h1 class="h4 mb-3">Gán tài xế: {{ $machine->asset_code }}</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('ops.assign-driver.submit', $machine) }}" class="card p-3">
            @csrf

            <div class="mb-3">
                <label class="form-label">Tài xế</label>

                <div class="d-flex gap-2">
                    <div class="flex-grow-1 position-relative">
                        <input type="hidden" name="driver_id" id="driverId" value="{{ old('driver_id') }}">

                        <input
                            type="text"
                            id="driverSearch"
                            class="form-control"
                            placeholder="Nhập tên tài xế để tìm..."
                            autocomplete="off"
                            value="{{ optional($drivers->firstWhere('id', old('driver_id')))->name }}"
                            required
                        >

                        <div class="form-text">Có thể gõ bất kỳ phần nào trong tên tài xế, ví dụ: Sềng, Nguyễn, Văn...</div>

                        <div
                            id="driverSuggestions"
                            class="list-group mt-1 position-absolute w-100 shadow-sm"
                            style="display: none; max-height: 260px; overflow-y: auto; z-index: 1000;"
                        ></div>
                    </div>

                    <a class="btn btn-outline-primary d-flex align-items-center" href="{{ route('drivers.create', ['redirect' => $redirect]) }}">
                        Thêm tài xế mới
                    </a>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Bắt đầu</label>
                <input type="datetime-local" name="started_at" class="form-control" value="{{ old('started_at') }}" required>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Xác nhận</button>
                <a class="btn btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Hủy</a>
            </div>
        </form>
    </div>

    <script>
        const drivers = @json($drivers->map(fn ($driver) => [
            'id' => $driver->id,
            'name' => $driver->name,
        ])->values());

        const driverSearch = document.getElementById('driverSearch');
        const driverId = document.getElementById('driverId');
        const suggestions = document.getElementById('driverSuggestions');

        function normalizeText(value) {
            return String(value || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/đ/g, 'd')
                .trim();
        }

        function hideSuggestions() {
            suggestions.style.display = 'none';
            suggestions.innerHTML = '';
        }

        function chooseDriver(driver) {
            driverSearch.value = driver.name;
            driverId.value = driver.id;
            driverSearch.classList.remove('is-invalid');
            hideSuggestions();
        }

        function renderSuggestions() {
            const keyword = normalizeText(driverSearch.value);

            driverId.value = '';
            driverSearch.classList.remove('is-invalid');

            if (!keyword) {
                hideSuggestions();
                return;
            }

            const matchedDrivers = drivers
                .filter(driver => normalizeText(driver.name).includes(keyword))
                .slice(0, 30);

            suggestions.innerHTML = '';

            if (matchedDrivers.length === 0) {
                const emptyItem = document.createElement('div');
                emptyItem.className = 'list-group-item text-muted';
                emptyItem.textContent = 'Không tìm thấy tài xế phù hợp';
                suggestions.appendChild(emptyItem);
                suggestions.style.display = 'block';
                return;
            }

            matchedDrivers.forEach(driver => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action';
                item.textContent = driver.name;
                item.addEventListener('click', () => chooseDriver(driver));
                suggestions.appendChild(item);
            });

            suggestions.style.display = 'block';
        }

        driverSearch.addEventListener('input', renderSuggestions);

        driverSearch.addEventListener('focus', function () {
            if (driverSearch.value.trim()) {
                renderSuggestions();
            }
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('#driverSearch') && !event.target.closest('#driverSuggestions')) {
                hideSuggestions();
            }
        });

        document.querySelector('form').addEventListener('submit', function (event) {
            if (!driverId.value) {
                event.preventDefault();
                driverSearch.classList.add('is-invalid');
                alert('Anh cần chọn đúng tài xế trong danh sách gợi ý.');
                driverSearch.focus();
            }
        });
    </script>
@endsection
