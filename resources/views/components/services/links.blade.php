@php
    $linkItemClasses = 'listbox-option justify-start! gap-2.5!';
@endphp

<div @class(['relative', 'w-full' => $fullWidth]) x-data="{ open: false }"
    x-effect="$dispatch('resource-actions-toggled', { open })" @keydown.escape.window="open = false">
    <button type="button" x-ref="trigger" @click="open = !open" @click.outside="open = false" title="Open service links"
        @class([
            'app-tab shrink-0 gap-1' => !$fullWidth && !$compact,
            'button w-full justify-between' => $fullWidth,
            'inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2 text-xs font-medium leading-none text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-white' => $compact,
        ])>
        <span class="inline-flex items-center gap-2">
            @unless ($compact)
                <x-reicon name="external-link" class="size-3.5 shrink-0 opacity-70" />
            @endunless
            Links
        </span>
        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
            <x-reicon name="chevron-down" class="size-3 opacity-55" />
        </span>
    </button>
    <div x-show="open" x-cloak x-transition.origin.top.right role="menu"
        @if ($compact) x-anchor.bottom-start.fixed.offset.4="$refs.trigger" @endif
        @class([
            'listbox-panel max-h-80! overflow-y-auto!',
            'top-full! mt-1!' => !$compact,
            'left-0! right-0! w-full! min-w-0! max-w-none!' => $fullWidth,
            'w-max! min-w-60! max-w-[min(28rem,calc(100vw-2rem))]!' => $compact,
            'right-0! left-auto! min-w-60! max-w-96!' => !$fullWidth && !$compact,
        ])>
        @forelse ($links as $link)
            <a class="{{ $linkItemClasses }}" target="_blank" href="{{ $link }}">
                <span class="min-w-0 truncate">{{ $link }}</span>
            </a>
        @empty
            <div class="listbox-option justify-start! cursor-default!">No links available</div>
        @endforelse
    </div>
</div>
