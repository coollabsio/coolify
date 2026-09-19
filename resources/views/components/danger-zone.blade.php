@props(['title'])

<div {{ $attributes->class('flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between') }}>
    <div class="min-w-0">
        <h4 class="text-sm font-semibold text-red-700 dark:text-red-300">{{ $title }}</h4>
        <div class="mt-2 max-w-2xl space-y-2 text-[13px] leading-5 text-red-700/80 dark:text-red-300/80">
            {{ $slot }}
        </div>
    </div>

    @isset($action)
        <div class="shrink-0">
            {{ $action }}
        </div>
    @endisset
</div>
