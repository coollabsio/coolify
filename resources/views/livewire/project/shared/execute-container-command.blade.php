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
        <livewire:project.service.navbar :service="$resource" :parameters="$parameters" title="Terminal" />
    @elseif ($type === 'server')
        <x-server.navbar :server="$server" :parameters="$parameters" />
    @endif
    @if ($type === 'server')
        <form class="w-full" wire:submit="$dispatchSelf('connectToServer')" wire:init="$dispatchSelf('connectToServer')">
            <x-forms.button class="w-full" type="submit">Reconnect</x-forms.button>
        </form>
        <div class="mx-auto w-full">
            <livewire:project.shared.terminal />
        </div>
    @else
        @if (count($containers) > 0)
            @if (count($containers) === 1)
                <form class="w-full pt-4"
                    wire:submit="$dispatchSelf('connectToContainer')" wire:init="$dispatchSelf('connectToContainer')">
                    <x-forms.button class="w-full" type="submit">Reconnect</x-forms.button>
                </form>
            @else
                <form class="w-full pt-4 flex gap-2 flex-col" wire:submit="$dispatchSelf('connectToContainer')">
                    <x-forms.select label="Container" id="container" required wire:model="selected_container">
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
                    <x-forms.button class="w-full" type="submit">
                        Connect
                    </x-forms.button>
                </form>
            @endif
            <div class="mx-auto w-full">
                <livewire:project.shared.terminal />
            </div>
        @else
            <div class="pt-4">No containers are running.</div>
        @endif
    @endif
</div>
