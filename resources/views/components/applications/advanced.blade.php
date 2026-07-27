<div class="relative" x-data="{ open: false }" @click.outside="open = false"
    @keydown.escape.window="open = false">
    <button type="button" class="button" @click="open = !open" :aria-expanded="open"
        aria-haspopup="menu">
        <x-reicon name="sliders" class="size-3.5 opacity-70" />
        Advanced
        <x-reicon name="chevron-down" class="size-3 opacity-55" />
    </button>

    <div x-cloak x-show="open" x-transition.origin.top.right
        class="listbox-panel top-full! right-0! left-auto! mt-1! w-60! min-w-0!" role="menu">
        @can('deploy', $application)
            <button type="button" class="listbox-option justify-start! gap-2.5!"
                wire:click="{{ $application->status === 'running' ? 'force_deploy_without_cache' : 'deploy(true)' }}"
                @click="open = false" role="menuitem">
                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                Force deploy without cache
            </button>
        @else
            <button type="button" class="listbox-option justify-start! gap-2.5!" disabled>
                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                Force deploy without cache
            </button>
        @endcan
    </div>
</div>
