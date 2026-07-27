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
                <x-helper :helper="$helper">
                    <x-slot:icon>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            class="size-4 stroke-current text-neutral-400 transition-colors hover:text-neutral-600 dark:text-fg-faint dark:hover:text-fg-dim">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </x-slot:icon>
                </x-helper>
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
