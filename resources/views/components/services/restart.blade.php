@props(['service'])

@php
    $status = str($service->status ?? '');
    $canDeploy = auth()->user()->can('deploy', $service);
    $isRunning = $status->contains('running');
    $isDegraded = $status->contains('degraded');
@endphp

@if ($isRunning || $isDegraded)
    @if ($isRunning)
        <div class="relative" x-data="{ open: false }"
            x-effect="$dispatch('resource-actions-toggled', { open })"
            @click.outside="open = false" @keydown.escape.window="open = false">
            <button type="button" class="button" @click="open = !open" :aria-expanded="open"
                aria-haspopup="menu" @disabled(! $canDeploy)>
                <x-reicon name="restart" class="size-3.5 opacity-70" />
                Restart
                <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                </span>
            </button>

            <div x-cloak x-show="open" x-transition.origin.top.right
                class="listbox-panel top-full! right-0! left-auto! mt-1! w-60! min-w-0!" role="menu">
                <button type="button" class="listbox-option justify-start! gap-2.5!"
                    @disabled(! $canDeploy)
                    @click="open = false; document.getElementById('service-restart-trigger')?.click()"
                    role="menuitem">
                    <x-reicon name="restart" class="size-3.5 opacity-70" />
                    Restart current version
                </button>
                <button type="button" class="listbox-option justify-start! gap-2.5!"
                    @disabled(! $canDeploy)
                    @click="$wire.dispatch('pullAndRestartEvent'); open = false"
                    role="menuitem">
                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                    Pull latest and restart
                </button>
            </div>
        </div>
    @else
        <button type="button" class="button"
            @disabled(! $canDeploy)
            @click="document.getElementById('service-restart-trigger')?.click()">
            <x-reicon name="restart" class="size-3.5 opacity-70" />
            Restart
        </button>
    @endif
@endif
