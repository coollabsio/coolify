@props([
    'title',
    'description' => null,
    'helper' => null,
    'flush' => false,
])

<section {{ $attributes->merge(['class' => 'application-settings-section']) }}>
    <header>
        <div class="flex items-center gap-2">
            <h3>{{ $title }}</h3>
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </div>
        @isset($actions)
            <div class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </header>
    <div class="application-settings-section-body {{ $flush ? 'is-flush' : '' }}">
        {{ $slot }}
    </div>
</section>
