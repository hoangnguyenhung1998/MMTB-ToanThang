@props([
    'title',
    'description' => null,
    'icon' => '—',
])

<div {{ $attributes->class(['empty-document-state']) }}>
    <div class="empty-icon">{{ $icon }}</div>
    <h3>{{ $title }}</h3>
    @if ($description)
        <p>{{ $description }}</p>
    @endif
    @isset($action)
        {{ $action }}
    @endisset
</div>
