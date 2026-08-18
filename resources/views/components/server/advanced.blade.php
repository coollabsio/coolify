@props([
    'serverIp',
    'traefikDashboardAvailable' => false,
])

<div class="relative" x-data="{ open: false }"
    x-effect="$dispatch('resource-actions-toggled', { open })"
    @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="button" @click="open = !open" :aria-expanded="open"
        aria-haspopup="menu">
        <x-reicon name="grid" class="size-3.5 opacity-70" />
        Advanced
        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
            <x-reicon name="chevron-down" class="size-3 opacity-55" />
        </span>
    </button>

    <div x-cloak x-show="open" x-transition.origin.top.right
        class="listbox-panel top-full! right-0! left-auto! mt-1! w-60! min-w-0!" role="menu">
        @if ($traefikDashboardAvailable)
            <a class="listbox-option justify-start! gap-2.5!" target="_blank"
                href="http://{{ $serverIp }}:8080" @click="open = false" role="menuitem">
                <span class="flex size-4 shrink-0 items-center justify-center">
                    <x-reicon name="external-link" class="size-3! opacity-70" />
                </span>
                Traefik Dashboard
            </a>
        @endif
        <button type="button" class="listbox-option justify-start! gap-2.5!"
            wire:click="checkProxyStatus" wire:loading.attr="disabled"
            @click="open = false" role="menuitem">
            <span class="flex size-4 shrink-0 items-center justify-center">
                <x-reicon name="refresh" class="size-3.5 opacity-70" />
            </span>
            Refresh Proxy Status
        </button>
    </div>
</div>
