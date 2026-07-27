<div @class([
    'group flex min-w-0 items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-3 transition-[border-color,background-color,box-shadow] dark:border-white/[0.07] dark:bg-surface',
    'cursor-not-allowed opacity-60' => $upgrade,
    'hover:border-neutral-300 hover:bg-neutral-50 hover:shadow-sm dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]' => ! $upgrade,
])>
    <div
        class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-neutral-100 text-black dark:bg-white/[0.06] dark:text-fg">
        {{ $logo }}
    </div>
    <div class="min-w-0 flex-1">
        <div class="truncate text-sm font-semibold text-black dark:text-fg">
            {{ $title }}
        </div>
        @if ($upgrade)
            <div class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">{{ $upgrade }}</div>
        @else
            <div class="mt-0.5 line-clamp-2 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                {{ $description }}
            </div>
        @endif
    </div>
    @unless ($upgrade)
        <x-reicon name="arrow-right"
            class="size-4 shrink-0 text-neutral-300 transition-transform group-hover:translate-x-0.5 dark:text-fg-faint" />
    @endunless
</div>
