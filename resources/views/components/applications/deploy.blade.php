@props(['application'])

@php
    $canDeploy = auth()->user()->can('deploy', $application);
    $isExited = str($application->status)->startsWith('exited');
    $isSwarm = $application->destination->server->isSwarm();
    $isCompose = $application->build_pack === 'dockercompose';
    $withoutCacheAction = $application->status === 'running' ? 'force_deploy_without_cache' : 'deploy(true)';
@endphp

@if ($isExited)
    @if ($isSwarm)
        <button type="button" class="button" @disabled(! $canDeploy) wire:click="deploy">
            <x-reicon name="play-circle" class="size-3.5 opacity-70" />
            Deploy
        </button>
    @else
        <div class="relative" x-data="{ open: false }"
            x-effect="$dispatch('resource-actions-toggled', { open })"
            @click.outside="open = false" @keydown.escape.window="open = false">
            <button type="button" class="button" @click="open = !open" :aria-expanded="open"
                aria-haspopup="menu" @disabled(! $canDeploy)>
                <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                Deploy
                <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                </span>
            </button>

            <div x-cloak x-show="open" x-transition.origin.top.right
                class="listbox-panel top-full! right-0! left-auto! mt-1! w-60! min-w-0!" role="menu">
                <button type="button" class="listbox-option justify-start! gap-2.5!"
                    @disabled(! $canDeploy) wire:click="deploy" @click="open = false" role="menuitem">
                    <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                    Deploy
                </button>
                <button type="button" class="listbox-option justify-start! gap-2.5!"
                    @disabled(! $canDeploy) wire:click="{{ $withoutCacheAction }}"
                    @click="open = false" role="menuitem">
                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                    Deploy (without cache)
                </button>
            </div>
        </div>
    @endif
@elseif (! $isSwarm)
    <div class="relative" x-data="{ open: false }"
        x-effect="$dispatch('resource-actions-toggled', { open })"
        @click.outside="open = false" @keydown.escape.window="open = false">
        <button type="button" class="button" @click="open = !open" :aria-expanded="open"
            aria-haspopup="menu" @disabled(! $canDeploy)>
            <x-reicon name="refresh" class="size-3.5 opacity-70" />
            Redeploy
            <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                <x-reicon name="chevron-down" class="size-3 opacity-55" />
            </span>
        </button>

        <div x-cloak x-show="open" x-transition.origin.top.right
            class="listbox-panel top-full! right-0! left-auto! mt-1! w-60! min-w-0!" role="menu">
            <button type="button" class="listbox-option justify-start! gap-2.5!"
                @disabled(! $canDeploy) wire:click="deploy" @click="open = false" role="menuitem">
                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                Deploy
            </button>
            <button type="button" class="listbox-option justify-start! gap-2.5!"
                @disabled(! $canDeploy) wire:click="{{ $withoutCacheAction }}"
                @click="open = false" role="menuitem">
                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                Deploy (without cache)
            </button>
        </div>
    </div>
@elseif (! $isCompose)
    <button type="button" class="button" @disabled(! $canDeploy) wire:click="deploy">
        <x-reicon name="refresh" class="size-3.5 opacity-70" />
        Update Service
    </button>
@endif
