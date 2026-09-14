@props(['workload', 'fullWidth' => false, 'compact' => false])

@php
    $internalHostname = $workload->internal_dns_name
        ? $workload->internal_dns_name.'.default.coolify.internal'
        : null;
    $linkItemClasses = 'listbox-option justify-start! gap-2.5!';
@endphp

<div @class([
    'relative' => ! $compact,
    'static' => $compact,
    'w-full' => $fullWidth,
]) x-data="{ open: false }"
    x-effect="$dispatch('resource-actions-toggled', { open })" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open" @click.outside="open = false"
        title="Open cluster application links"
        @class([
            'app-tab shrink-0 gap-1' => ! $fullWidth && ! $compact,
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
        @class([
            'listbox-panel top-full! mt-1!',
            'left-0! right-0! w-full! min-w-0! max-w-none!' => $fullWidth,
            'left-1/2! right-auto! w-[calc(100vw-2rem)]! max-w-md! min-w-0! -translate-x-1/2' => $compact,
            'right-0! left-auto! min-w-72! max-w-96!' => ! $fullWidth && ! $compact,
        ])>
        @if ($internalHostname)
            <a class="{{ $linkItemClasses }}" target="_blank" href="http://{{ $internalHostname }}">
                <span class="shrink-0 rounded-md bg-success/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-success ring-1 ring-success/20">
                    Internal mesh
                </span>
                <span class="min-w-0 truncate">{{ $internalHostname }}</span>
            </a>
        @else
            <div class="{{ $linkItemClasses }} cursor-default!">
                <span class="shrink-0 rounded-md bg-neutral-500/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-neutral-500 ring-1 ring-neutral-500/20">
                    Internal mesh
                </span>
                <span class="text-neutral-500 dark:text-fg-dim">Pending</span>
            </div>
        @endif
        <div class="{{ $linkItemClasses }} cursor-default!" aria-disabled="true">
            <span class="shrink-0 rounded-md bg-neutral-500/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-neutral-500 ring-1 ring-neutral-500/20">
                External
            </span>
            <span class="text-neutral-500 dark:text-fg-dim">Not available yet</span>
        </div>
    </div>
</div>
