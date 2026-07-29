@props([
    'label',
    'value',
    'hint' => null,
    'icon' => null,
    'tone' => 'neutral',
])

<div {{ $attributes->class(['document-kpi', 'app-card', 'kpi-' . $tone]) }}>
    @if ($icon)
        <div class="kpi-icon">{{ $icon }}</div>
    @endif
    <div>
        <span>{{ $label }}</span>
        <strong>{{ $value }}</strong>
        @if ($hint)
            <small>{{ $hint }}</small>
        @endif
    </div>
</div>
