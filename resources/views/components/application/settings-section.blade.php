@props([
    'title',
    'description' => null,
    'helper' => null,
    'flush' => false,
])

<section {{ $attributes->merge(['class' => 'application-settings-section']) }}>
    <header>
        <div class="min-w-0 py-0.5">
            @if (filled($description ?? $helper))
                <h3>
                    <x-helper :helper="$description ?? $helper" :label="'More information about '.$title">
                        <x-slot:trigger>
                            <span class="underline underline-offset-4">{{ $title }}</span>
                        </x-slot:trigger>
                    </x-helper>
                </h3>
            @else
                <h3>{{ $title }}</h3>
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
