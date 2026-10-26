@props(['theme'])

{{-- Small picture of a custom theme: sidebar with the selected row, page with a card.
     Uses textOn() from the parent themeControls() scope. $theme is an Alpine expression. --}}
<div {{ $attributes->merge(['class' => 'flex overflow-hidden rounded-md border border-black/10 dark:border-white/10']) }}
    aria-hidden="true">
    <div class="flex w-[38%] flex-col gap-1 p-1.5" :style="`background: ${ {{ $theme }}.sidebar }`">
        <span class="h-1 w-3/4 rounded-full opacity-50" :style="`background: ${textOn({{ $theme }}.sidebar)}`"></span>
        <span class="h-2.5 w-full rounded-sm" :style="`background: ${ {{ $theme }}.selected }`"></span>
        <span class="flex items-center gap-1">
            <span class="h-1 w-1/2 rounded-full opacity-50" :style="`background: ${textOn({{ $theme }}.sidebar)}`"></span>
            <span class="size-1.5 rounded-[2px]" :style="`background: ${ {{ $theme }}.accent }`"></span>
        </span>
    </div>
    <div class="flex flex-1 flex-col gap-1 p-1.5" :style="`background: ${ {{ $theme }}.background }`">
        <span class="h-1 w-2/3 rounded-full opacity-60" :style="`background: ${textOn({{ $theme }}.background)}`"></span>
        <span class="h-1 w-1/2 rounded-full opacity-30" :style="`background: ${textOn({{ $theme }}.background)}`"></span>
        <span class="mt-auto h-2.5 w-full rounded-sm border"
            :style="`border-color: color-mix(in oklab, ${textOn({{ $theme }}.background)} 25%, transparent)`"></span>
    </div>
</div>
