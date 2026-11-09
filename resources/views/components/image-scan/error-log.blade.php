@props(['detail' => null])

@if (filled($detail))
    <details class="group/error-log mt-2">
        <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-md text-[12px] font-medium text-red-800 hover:text-red-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coollabs dark:text-red-300 dark:hover:text-red-200 dark:focus-visible:outline-brand [&::-webkit-details-marker]:hidden">
            <x-reicon name="chevron-down" class="size-3.5 shrink-0 -rotate-90 group-open/error-log:rotate-0" />
            Show error log
        </summary>
        <pre class="mt-2 max-h-60 overflow-auto whitespace-pre-wrap wrap-anywhere rounded-lg bg-white p-3 font-mono text-xs leading-5 text-neutral-700 ring-1 ring-neutral-200 dark:bg-neutral-950 dark:text-neutral-300 dark:ring-white/[0.08]">{{ $detail }}</pre>
    </details>
@endif
