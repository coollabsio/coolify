@props(['label', 'items', 'title'])

<span class="shrink-0 px-0.5 text-neutral-300 dark:text-fg-faint">/</span>
<div class="relative min-w-0 shrink" x-data="{ open: false, search: '' }" @keydown.escape.window="open = false"
    @click.outside="open = false">
    <div class="flex h-8 min-w-0 items-center gap-1">
        <button type="button"
            @click="open = !open; if (open) { search = ''; $nextTick(() => $refs.search.focus()) }"
            title="Switch resource"
            class="flex h-8 min-w-0 items-center gap-1.5 rounded-md px-2 opacity-70 transition-[background-color,opacity] hover:bg-neutral-100 hover:opacity-100 dark:hover:bg-white/[0.05]">
            <span class="min-w-0 truncate font-semibold text-black dark:text-fg">{{ $label }}</span>
            <svg class="size-4 shrink-0 text-neutral-400 dark:text-fg-faint" viewBox="0 0 24 24" fill="none">
                <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.6"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        @isset($meta)
            {{ $meta }}
        @endisset
    </div>

    <div x-show="open" x-cloak x-transition.opacity.duration.120ms
        class="listbox-panel scrollbar left-0! z-[90]! max-h-80! min-w-56 max-w-72">
        <div class="searchable-listbox-search">
            <x-reicon name="search"
                class="pointer-events-none absolute top-1/2 left-4 size-3 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
            <input x-ref="search" x-model.debounce.150ms="search" type="search"
                autocomplete="off" placeholder="Search {{ strtolower($title) }}"
                class="searchable-listbox-search-input" @keydown.escape.stop="open = false">
        </div>
        <div class="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-400 dark:text-fg-faint">
            {{ $title }}
        </div>
        @foreach ($items as $item)
            <a href="{{ $item['href'] }}" {{ wireNavigate() }} @click="open = false"
                x-show="@js(strtolower($item['label'])).includes(search.toLowerCase())"
                class="listbox-option {{ $item['active'] ? 'bg-neutral-100 font-medium text-black dark:bg-white/[0.07] dark:text-fg' : '' }}">
                <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
