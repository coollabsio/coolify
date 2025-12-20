<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > {{ __('execute.title') }} | Coolify
    </x-slot>
    @if ($type === 'application')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>{{ __('menu.terminal') }}</h1>
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>{{ __('menu.terminal') }}</h1>
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" title="{{ __('menu.terminal') }}" />
    @endif

    @if ($type === 'application' || $type === 'database' || $type === 'service')
        <h2 class="pb-4">{{ __('menu.terminal') }}</h2>
        @if (count($containers) === 0)
            <div>{{ __('execute.no_containers') }}</div>
        @else
            <form class="w-96 min-w-fit flex gap-2 items-end" wire:submit="$dispatchSelf('connectToContainer')"
                x-data="{ autoConnected: false }" x-init="if ({{ count($containers) }} === 1 && !autoConnected) {
                    autoConnected = true;
                    $nextTick(() => $wire.dispatchSelf('connectToContainer'));
                }">
                <x-forms.select label="{{ __('execute.container_label') }}" id="container" required wire:model.live="selected_container">
                    @foreach ($containers as $container)
                        @if ($loop->first)
                            <option disabled value="default">{{ __('execute.select_container') }}</option>
                        @endif
                        <option value="{{ data_get($container, 'container.Names') }}">
                            {{ data_get($container, 'container.Names') }}
                            ({{ data_get($container, 'server.name') }})
                        </option>
                    @endforeach
                </x-forms.select>
                <x-forms.button :disabled="$isConnecting"
                    type="submit">{{ $isConnecting ? __('execute.connecting') : __('execute.connect') }}</x-forms.button>
            </form>
            <div class="mx-auto w-full">
                <livewire:project.shared.terminal />
            </div>
        @endif
    @endif

    @if ($type === 'server')
        <livewire:server.navbar :server="$servers->first()" />
        @if ($servers->first()->isTerminalEnabled() && $servers->first()->isFunctional())
            <form class="w-full flex gap-2 items-start" wire:submit="$dispatchSelf('connectToServer')"
                x-data="{ autoConnected: false }"
                x-on:terminal-websocket-ready.window="if (!autoConnected) { autoConnected = true; $wire.dispatchSelf('connectToServer'); }">
                <h2 class="pb-4">{{ __('menu.terminal') }}</h2>
                <x-forms.button :disabled="$isConnecting"
                    type="submit">{{ $isConnecting ? __('execute.connecting') : __('execute.connect') }}</x-forms.button>
            </form>
            <div class="mx-auto w-full">
                <livewire:project.shared.terminal />
            </div>
        @else
            <div>{{ __('execute.server_not_functional') }}</div>
        @endif
    @endif
</div>
