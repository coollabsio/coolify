@php
    $linkItemClasses =
        'flex items-center gap-2 px-3 h-8 text-[13px] transition-colors text-neutral-600 dark:text-fg-dim hover:bg-neutral-100 dark:hover:bg-white/[0.06] hover:text-black dark:hover:text-fg';
@endphp

@if ($links->count() > 0)
    <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
        <button type="button" @click="open = !open" @click.outside="open = false" title="Open service links"
            class="app-tab shrink-0 gap-1">
            <x-reicon name="external-link" class="size-3.5 shrink-0 opacity-70" />
            Links
            <svg class="size-3.5 shrink-0 opacity-60" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
                    stroke-linejoin="round" />
            </svg>
        </button>
        <div x-show="open" x-cloak x-transition.opacity.duration.120ms
            class="absolute right-0 z-[90] mt-2 min-w-60 max-w-96 max-h-80 overflow-y-auto rounded-lg border border-neutral-200 bg-white py-1.5 shadow-modal scrollbar dark:border-white/10 dark:bg-raised md:left-0 md:right-auto">
            @foreach ($links as $link)
                <a class="{{ $linkItemClasses }}" target="_blank" href="{{ $link }}">
                    <span class="min-w-0 truncate">{{ $link }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
