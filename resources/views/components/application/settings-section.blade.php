@props([
    'title',
    'description' => null,
    'helper' => null,
    'flush' => false,
    // A collapsible card shows only its title row until the user opens it.
    'collapsible' => false,
    'open' => false,
])

@php
    $bodyId = $collapsible ? ($attributes->get('id') ?: 'section-'.md5($title)).'-body' : null;
    // A card with nothing in its body shows only its title row.
    $hasBody = $collapsible || $slot->hasActualContent();
@endphp

<section {{ $attributes->merge(['class' => 'application-settings-section', 'data-settings-section-title' => $title]) }}
    @if ($collapsible) x-data="{ open: @js((bool) $open) }"
        x-on:open-settings-section.window="if ($event.detail?.id === $el.id) { open = true; $nextTick(() => $el.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' })) }" @endif>
    <header @if ($collapsible) :class="open || 'is-collapsed'" @elseif (! $hasBody) class="is-collapsed" @endif>
        <div @class(['min-w-0 py-0.5', 'flex flex-wrap items-baseline gap-x-2 gap-y-1' => isset($badges)])>
            @if ($collapsible)
                <h3 @class(['flex items-center gap-1.5' => filled($description ?? $helper)])>
                    <button type="button" class="settings-section-toggle" x-on:click="open = ! open"
                        x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $bodyId }}">
                        <x-reicon name="chevron-down" class="size-3.5 shrink-0 transition-transform duration-150"
                            ::class="open ? 'rotate-0' : '-rotate-90'" />
                        <span>{{ $title }}</span>
                    </button>
                    @if (filled($description ?? $helper))
                        <x-helper :helper="$description ?? $helper" :label="'More information about '.$title" />
                    @endif
                </h3>
            @elseif (filled($description ?? $helper))
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
            {{ $badges ?? '' }}
        </div>
        @isset($actions)
            <div class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </header>
    @if ($hasBody)
        <div @if ($collapsible) id="{{ $bodyId }}" x-show="open" x-cloak @endif
            class="application-settings-section-body {{ $flush ? 'is-flush' : '' }}">
            {{ $slot }}
        </div>
    @endif
</section>
