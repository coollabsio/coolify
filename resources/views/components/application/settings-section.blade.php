@props([
    'title',
    'description' => null,
    'helper' => null,
    'flush' => false,
])

<section {{ $attributes->merge(['class' => 'application-settings-section']) }}>
    <header @class(['items-start!' => filled($description ?? $helper)])>
        <div class="min-w-0 py-0.5">
            <h3>{{ $title }}</h3>
            @if (filled($description ?? $helper))
                <p class="mt-0.5 text-xs leading-4 text-neutral-500 dark:text-[var(--coollabs-subtle)]">
                    {!! $description ?? $helper !!}
                </p>
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
