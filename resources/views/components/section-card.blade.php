@props([
    'number' => null,
    'title',
    'subtitle' => null,
    'optional' => false,
])

<section {{ $attributes->class(['app-card', 'form-section-card']) }}>
    <div class="section-heading">
        @if ($number)
            <div class="section-icon">{{ $number }}</div>
        @endif
        <div>
            <h2>{{ $title }}</h2>
            @if ($subtitle)
                <p>{{ $subtitle }}</p>
            @endif
        </div>
        @if ($optional)
            <span class="optional-chip">Không bắt buộc</span>
        @endif
    </div>

    {{ $slot }}
</section>
