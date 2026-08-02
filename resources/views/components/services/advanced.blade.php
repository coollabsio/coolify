@php
    $status = str($service->status ?? '');
    $canDeploy = auth()->user()->can('deploy', $service);
    $canStop = auth()->user()->can('stop', $service);
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false"
    @keydown.escape.window="open = false">
    <button type="button" class="button" @click="open = !open" :aria-expanded="open"
        aria-haspopup="menu">
        Advanced
        <x-reicon name="chevron-down" class="size-3 opacity-55" />
    </button>

    <div x-cloak x-show="open" x-transition.origin.top.right
        class="listbox-panel top-full! right-0! left-auto! mt-1! w-64! min-w-0!" role="menu">
        @if ($status->contains('running'))
            <button type="button" class="listbox-option justify-start! gap-2.5!"
                @disabled(! $canDeploy)
                @click="$wire.dispatch('pullAndRestartEvent'); open = false"
                role="menuitem">
                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                Pull Latest Images & Restart
            </button>
        @elseif ($status->contains('degraded'))
            <button type="button" class="listbox-option justify-start! gap-2.5!"
                @disabled(! $canDeploy)
                @click="$wire.dispatch('forceDeployEvent'); open = false"
                role="menuitem">
                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                Force Restart
            </button>
        @else
            <button type="button" class="listbox-option justify-start! gap-2.5!"
                @disabled(! $canDeploy)
                @click="$wire.dispatch('forceDeployEvent'); open = false"
                role="menuitem">
                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                Force Deploy
            </button>
            <button type="button" class="listbox-option justify-start! gap-2.5!"
                @disabled(! $canStop)
                @click="$wire.dispatch('cleanupEvent'); open = false"
                role="menuitem">
                <x-reicon name="trash" class="size-3.5 opacity-70" />
                Force Cleanup Containers
            </button>
        @endif
    </div>
</div>
