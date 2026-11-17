@props(['title'])

<div
    {{ $attributes->class('flex flex-col gap-4 rounded-lg border border-red-200 bg-red-50 p-5 sm:flex-row sm:items-start sm:justify-between dark:border-red-500/25 dark:bg-red-500/[0.08]') }}>
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h4 class="text-sm font-semibold text-black dark:text-fg">{{ $title }}</h4>
            <span
                class="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 bg-white px-2.5 py-0.5 text-xs font-medium text-neutral-700 dark:border-white/[0.08] dark:bg-white/[0.06] dark:text-fg">
                <span class="size-1.5 rounded-full bg-red-500"></span>
                Permanent
            </span>
        </div>
        <div class="mt-2 max-w-2xl space-y-2 text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
            {{ $slot }}
        </div>
    </div>

    @isset($action)
        <div class="shrink-0">
            {{ $action }}
        </div>
    @endisset
</div>
