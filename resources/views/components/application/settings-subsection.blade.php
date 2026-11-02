@props([
    'title',
    'helper' => null,
    // The first sub-section of a card has no top divider.
    'first' => false,
])

<section {{ $attributes->class(['mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]' => ! $first]) }}>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h3 class="flex items-center gap-1.5 text-sm! font-semibold! text-black dark:text-fg">
            {{ $title }}
            @if (filled($helper))
                <x-helper :helper="$helper" :label="'More information about '.$title" />
            @endif
        </h3>
        @isset($actions)
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>
    {{ $slot }}
</section>
