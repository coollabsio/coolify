@php
    $linkItemClasses = 'listbox-option justify-start! gap-2.5!';
@endphp

<div @class(['relative', 'w-full' => $fullWidth]) x-data="{ open: false }" @keydown.escape.window="open = false">
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
    <div x-show="open" x-cloak x-transition.origin.top.right
        class="listbox-panel top-full! right-0! left-auto! mt-1! min-w-60! max-w-96! max-h-80! overflow-y-auto!"
        role="menu">
        @forelse ($links as $link)
            <a class="{{ $linkItemClasses }}" target="_blank" href="{{ $link }}">
                <span class="min-w-0 truncate">{{ $link }}</span>
            </a>
        @empty
            <div class="listbox-option justify-start! cursor-default!">No links available</div>
        @endforelse
    </div>
</div>
