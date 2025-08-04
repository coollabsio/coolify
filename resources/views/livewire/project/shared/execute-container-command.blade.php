<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Commands | Coolify
    </x-slot>
    @if ($type === 'application')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>Terminal</h1>
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>Terminal</h1>
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" title="Terminal" />
    @endif

    @if ($type === 'application' || $type === 'database' || $type === 'service')
        <h2 class="pb-4">Terminal</h2>
        @if (count($containers) === 0)
            <div>No containers are running or terminal access is disabled on this server.</div>
        @else
            <form class="w-full flex gap-2 items-end" wire:submit="$dispatchSelf('connectToContainer')">
                <x-forms.select label="Container" id="container" required wire:model.live="selected_container">
                    @foreach ($containers as $container)
                        @if ($loop->first)
                            <option disabled value="default">Select a container</option>
                        @endif
                        <option value="{{ data_get($container, 'container.Names') }}">
                            {{ data_get($container, 'container.Names') }}
                            ({{ data_get($container, 'server.name') }})
                        </option>
                    @endforeach
                </x-forms.select>
                <x-forms.button :disabled="$isConnecting"
                    type="submit">{{ $isConnecting ? 'Connecting...' : 'Connect' }}</x-forms.button>
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
                <h2 class="pb-4">Terminal</h2>
                <x-forms.button :disabled="$isConnecting"
                    type="submit">{{ $isConnecting ? 'Connecting...' : 'Connect' }}</x-forms.button>
            </form>
            <div class="mx-auto w-full">
                <livewire:project.shared.terminal />
            </div>
        @else
            <div>Server is not functional or terminal access is disabled.</div>
        @endif
    @endif
</div>
