@php
    $linkItemClasses = 'listbox-option justify-start! gap-2.5!';
@endphp

<div @class(['relative', 'w-full' => $fullWidth]) x-data="{ open: false }"
    x-effect="$dispatch('resource-actions-toggled', { open })" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open" @click.outside="open = false" title="Open service links"
        @class([
            'app-tab shrink-0 gap-1' => !$fullWidth,
            'button w-full justify-between' => $fullWidth,
        ])>
        <span class="inline-flex items-center gap-2">
            <x-reicon name="external-link" class="size-3.5 shrink-0 opacity-70" />
            Links
        </span>
        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
            <x-reicon name="chevron-down" class="size-3 opacity-55" />
        </span>
    </button>
    <div x-show="open" x-cloak x-transition.origin.top.right role="menu"
        @class([
            'listbox-panel top-full! mt-1! max-h-80! overflow-y-auto!',
            'left-0! right-0! w-full! min-w-0! max-w-none!' => $fullWidth,
            'right-0! left-auto! min-w-60! max-w-96!' => !$fullWidth,
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
