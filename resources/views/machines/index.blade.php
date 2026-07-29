@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Danh sách máy</h1>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" href="{{ route('machines.import.form') }}">Import Excel</a>
                <a class="btn btn-outline-success" href="{{ route('machines.export', request()->query()) }}">Xuất Excel</a>
                <a class="btn btn-primary" href="{{ route('machines.create') }}">Thêm máy</a>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php
            use Carbon\Carbon;

            $badgeMap = [
                'WAIT_HANDOVER' => 'bg-secondary',
                'HANDED_OVER' => 'bg-primary',
                'ACTIVE' => 'bg-success',
                'RETURNED' => 'bg-danger',
            ];
        @endphp

        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-3">
                <input type="text" name="q" class="form-control" placeholder="Tìm theo mã máy" value="{{ $search }}">
            </div>
            <div class="col-12 col-md-2">
                <select class="form-select" name="company">
                    <option value="">-- Company --</option>
                    <option value="VINCONS" @selected(($filters['company'] ?? '') === 'VINCONS')>VINCONS</option>
                    <option value="VINALPHA" @selected(($filters['company'] ?? '') === 'VINALPHA')>VINALPHA</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <select class="form-select" name="status">
                    <option value="">-- Trạng thái --</option>
                    @foreach (['WAIT_HANDOVER', 'HANDED_OVER', 'ACTIVE', 'RETURNED'] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2">
                <select class="form-select" name="project_id">
                    <option value="">-- Dự án --</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) ($filters['project_id'] ?? '') === (string) $project->id)>
                            {{ $project->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2">
                <select class="form-select" name="command_center_id">
                    <option value="">-- BCH --</option>
                    @foreach ($commandCenters as $commandCenter)
                        <option value="{{ $commandCenter->id }}" @selected((string) ($filters['command_center_id'] ?? '') === (string) $commandCenter->id)>
                            {{ $commandCenter->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2">
                <select class="form-select" name="return_app_status">
                    <option value="">-- App trả --</option>
                    <option value="pending" @selected(($filters['return_app_status'] ?? '') === 'pending')>Chưa đẩy app trả</option>
                </select>
            </div>
            <div class="col-12 col-md-1 d-grid">
                <button class="btn btn-outline-primary" type="submit">Lọc</button>
            </div>
            <div class="col-12 col-md-1 d-grid">
                <a class="btn btn-outline-secondary" href="{{ route('machines.index') }}">Xóa lọc</a>
            </div>
        </form>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="text-muted">Đã chọn: <span id="selectedCount">0</span> máy</div>
            <button class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#batchActionModal" id="openBatchModal" disabled>
                Hành động hàng loạt
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll">
                        </th>
                        <th>Mã máy</th>

                        <th>Trạng thái</th>
                        <th>Công ty</th>
                        <th>Số khung</th>
                        <th>Số máy</th>
                        <th>Biển số</th>
                        <th>Năm SX</th>
                        <th>Dự án hiện tại</th>
                        <th>BCH</th>
                        <th>Tài xế</th>
                        <th>Ngày Vào</th>
                        <th>Ngày Ra</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($machines as $machine)
                        @php
                            $currentAssignment = $machine->assignments->firstWhere('time_out', null);
                            $today = Carbon::today();
                            $documentBadges = [];

                            foreach ($machine->documents as $document) {
                                if (!$document->expiry_date) {
                                    continue;
                                }

                                $expiryDate = Carbon::parse($document->expiry_date)->startOfDay();

                                $daysLeft = $today->diffInDays($expiryDate, false);

                                if ($daysLeft < 0) {
                                    $documentBadges[$document->doc_type . '_expired'] = [
                                        'class' => 'bg-danger',
                                        'text' => $document->doc_type . ' hết hạn',
                                    ];
                                } elseif ($daysLeft <= 30) {
                                    $documentBadges[$document->doc_type . '_soon'] = [
                                        'class' => 'bg-warning text-dark',
                                        'text' => $document->doc_type . ' sắp hết hạn',
                                    ];
                                }
                            }
                        @endphp
                        <tr>
                            <td>
                                <input class="machine-checkbox" type="checkbox" value="{{ $machine->id }}" data-asset-code="{{ $machine->asset_code }}">
                            </td>
                            <td>{{ $machine->asset_code }}</td>
                            
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    <span class="badge {{ $badgeMap[$machine->status] ?? 'bg-secondary' }}">
                                        {{ $machine->status }}
                                    </span>
                                    @if ($machine->has_missing_handover_proof)
                                        <span class="badge bg-danger">Thiếu biên bản bàn giao</span>
                                    @endif
                                    @if ($machine->status === 'RETURNED' && !$machine->returned_to_app)
                                        <span class="badge bg-danger">Chưa đẩy app trả</span>
                                    @endif
                                    @foreach ($documentBadges as $documentBadge)
                                        <span class="badge {{ $documentBadge['class'] }}">{{ $documentBadge['text'] }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>{{ $machine->company }}</td>
                            <td>{{ $machine->chassis_no }}</td>
                            <td>{{ $machine->engine_no }}</td>
                            <td>{{ $machine->plate_no }}</td>
                            <td>{{ $machine->manufacture_year ?? '-' }}</td>
                            <td>{{ $currentAssignment?->project?->name ?? '-' }}</td>
                            <td>{{ $machine->currentAssignment?->commandCenter?->name ?? 'Chưa có BCH' }}</td>
                            <td>{{ $machine->currentDriver?->name ?? '-' }}</td>
                            <td>{{ $machine->latestAssignment?->time_in?->format('d/m/Y') ?? '---' }}</td>
                            <td>
                                @if ($machine->status === 'ACTIVE')
                                    Đang hoạt động
                                @else
                                    {{ $machine->latestAssignment?->time_out?->format('d/m/Y') ?? '---' }}
                                @endif
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('machines.show', $machine) }}">Xem</a>
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('machines.edit', $machine) }}">Sửa</a>
                                <form method="POST" action="{{ route('machines.delete', $machine) }}" class="d-inline" onsubmit="return confirm('Xoá máy này?')">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Xoá</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="14" class="text-center text-muted">Chưa có dữ liệu máy.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>
            {{ $machines->links() }}
        </div>
    </div>

    <div class="modal fade" id="batchActionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Hành động hàng loạt</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Chọn hành động</label>
                        <select class="form-select" id="batchActionType">
                            <option value="">-- Chọn --</option>
                            <option value="handover">Bàn giao hàng loạt (không biên bản)</option>
                            <option value="activate">Kích hoạt hàng loạt (ACTIVE)</option>
                            <option value="export">Xuất Excel (máy đã chọn)</option>
                            <option value="delete">Xóa hàng loạt</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="handoverFields">
                        <label class="form-label">Dự án</label>
                        <select class="form-select mb-2" id="handoverProjectId">
                            <option value="">-- Chọn dự án --</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}">{{ $project->name }}</option>
                            @endforeach
                        </select>

                        <label class="form-label">BCH</label>
                        <select class="form-select mb-2" id="handoverCommandCenterId">
                            <option value="">-- Chọn BCH --</option>
                            @foreach ($commandCenters as $commandCenter)
                                <option value="{{ $commandCenter->id }}">{{ $commandCenter->name }}</option>
                            @endforeach
                        </select>

                        <label class="form-label">Thời gian bàn giao</label>
                        <input type="datetime-local" class="form-control mb-2" id="handoverTimeIn">

                        <label class="form-label">Ghi chú</label>
                        <textarea class="form-control" id="handoverNote" rows="2"></textarea>
                    </div>

                    <div class="mb-3 d-none" id="activateAtWrapper">
                        <label class="form-label">Thời gian kích hoạt</label>
                        <input type="datetime-local" class="form-control" id="activatedAtInput">
                    </div>

                    <div class="mb-3 d-none" id="deleteConfirmWrapper">
                        <label class="form-label">Nhập <strong>XOA</strong> để xác nhận xóa</label>
                        <input type="text" class="form-control" id="deleteConfirmInput" placeholder="XOA">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="btn btn-primary" id="submitBatchAction">Thực hiện</button>
                </div>
            </div>
        </div>
    </div>

    <form id="batchHandoverForm" method="POST" action="{{ route('machines.batch.handover') }}" class="d-none">@csrf</form>
    <form id="batchActivateForm" method="POST" action="{{ route('machines.batch.activate') }}" class="d-none">@csrf</form>
    <form id="batchExportForm" method="POST" action="{{ route('machines.batch.export') }}" class="d-none">@csrf</form>
    <form id="batchDeleteForm" method="POST" action="{{ route('machines.batch.delete') }}" class="d-none">@csrf</form>

    <script>
        const selectAll = document.getElementById('selectAll');
        const checkboxes = Array.from(document.querySelectorAll('.machine-checkbox'));
        const selectedCount = document.getElementById('selectedCount');
        const openBatchModalBtn = document.getElementById('openBatchModal');
        const batchActionType = document.getElementById('batchActionType');
        const handoverFields = document.getElementById('handoverFields');
        const handoverProjectId = document.getElementById('handoverProjectId');
        const handoverCommandCenterId = document.getElementById('handoverCommandCenterId');
        const handoverTimeIn = document.getElementById('handoverTimeIn');
        const handoverNote = document.getElementById('handoverNote');
        const activateAtWrapper = document.getElementById('activateAtWrapper');
        const activatedAtInput = document.getElementById('activatedAtInput');
        const deleteConfirmWrapper = document.getElementById('deleteConfirmWrapper');
        const deleteConfirmInput = document.getElementById('deleteConfirmInput');
        const submitBatchAction = document.getElementById('submitBatchAction');

        function getSelectedIds() {
            return checkboxes.filter(cb => cb.checked).map(cb => cb.value);
        }

        function renderSelectedState() {
            const selected = getSelectedIds();
            selectedCount.innerText = selected.length;
            openBatchModalBtn.disabled = selected.length === 0;
            if (selected.length === 0) {
                selectAll.checked = false;
            } else if (selected.length === checkboxes.length) {
                selectAll.checked = true;
            } else {
                selectAll.checked = false;
            }
        }

        selectAll?.addEventListener('change', function() {
            checkboxes.forEach(cb => cb.checked = this.checked);
            renderSelectedState();
        });

        checkboxes.forEach(cb => cb.addEventListener('change', renderSelectedState));

        batchActionType.addEventListener('change', function() {
            handoverFields.classList.toggle('d-none', this.value !== 'handover');
            activateAtWrapper.classList.toggle('d-none', this.value !== 'activate');
            deleteConfirmWrapper.classList.toggle('d-none', this.value !== 'delete');
        });

        function submitFormWithIds(formId, ids, extras = {}) {
            const form = document.getElementById(formId);
            //form.querySelectorAll('input[type="hidden"], textarea[type="hidden"]').forEach(i => i.remove());
            form.querySelectorAll('input[type="hidden"]:not([name="_token"])').forEach(i => i.remove());

            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'machine_ids[]';
                input.value = id;
                form.appendChild(input);
            });

            Object.entries(extras).forEach(([key, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            });

            form.submit();
        }

        submitBatchAction.addEventListener('click', function() {
            const action = batchActionType.value;
            const ids = getSelectedIds();

            if (!action) {
                alert('Vui lòng chọn hành động.');
                return;
            }

            if (!ids.length) {
                alert('Vui lòng chọn ít nhất 1 máy.');
                return;
            }

            if (action === 'handover') {
                if (!handoverProjectId.value || !handoverCommandCenterId.value || !handoverTimeIn.value) {
                    alert('Vui lòng nhập đủ Dự án, BCH và Thời gian bàn giao.');
                    return;
                }
                submitFormWithIds('batchHandoverForm', ids, {
                    project_id: handoverProjectId.value,
                    command_center_id: handoverCommandCenterId.value,
                    time_in: handoverTimeIn.value,
                    note: handoverNote.value
                });
                return;
            }

            if (action === 'activate') {
                if (!activatedAtInput.value) {
                    alert('Vui lòng nhập thời gian kích hoạt.');
                    return;
                }
                submitFormWithIds('batchActivateForm', ids, { activated_at: activatedAtInput.value });
                return;
            }

            if (action === 'export') {
                submitFormWithIds('batchExportForm', ids);
                return;
            }

            if (action === 'delete') {
                if (deleteConfirmInput.value !== 'XOA') {
                    alert('Bạn cần nhập đúng XOA để xác nhận xóa.');
                    return;
                }
                submitFormWithIds('batchDeleteForm', ids, { delete_confirm: deleteConfirmInput.value });
            }
        });
    </script>
@endsection
