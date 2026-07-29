@props([
    'status',
    'label' => null,
])

@php
    $statusMap = [
        'WAIT_HANDOVER' => ['label' => 'Chờ bàn giao', 'class' => 'status-wait'],
        'HANDED_OVER' => ['label' => 'Đã bàn giao', 'class' => 'status-handover'],
        'ACTIVE' => ['label' => 'Đang hoạt động', 'class' => 'status-active'],
        'RETURNED' => ['label' => 'Đã trả', 'class' => 'status-returned'],
    ];
    $config = $statusMap[$status] ?? ['label' => $status, 'class' => 'status-wait'];
@endphp

<span {{ $attributes->class(['status-badge', $config['class']]) }}>
    {{ $label ?? $config['label'] }}
</span>
