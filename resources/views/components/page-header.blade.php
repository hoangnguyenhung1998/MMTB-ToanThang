@props([
    'title',
    'subtitle' => null,
    'eyebrow' => null,
])

<div {{ $attributes->class(['page-header']) }}>
    <div>
        @if ($eyebrow)
            <div class="page-eyebrow">{{ $eyebrow }}</div>
        @endif
        <h1 class="page-title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="page-subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
