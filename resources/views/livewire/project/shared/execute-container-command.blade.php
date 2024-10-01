<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Commands | Coolify
    </x-slot>
    <livewire:project.shared.configuration-checker :resource="$resource" />
    @if ($type === 'application')
        <h1>Terminal</h1>
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <h1>Terminal</h1>
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.service.navbar :service="$resource" :parameters="$parameters" title="Terminal" />
    @endif
    <div x-init="$wire.loadContainers">
        <div class="pt-4" wire:loading wire:target='loadContainers'>
            Loading resources...
        </div>
        <div wire:loading.remove wire:target='loadContainers'>
            @if (count($containers) > 0)
                <form class="flex flex-col gap-2 justify-center pt-4 xl:items-end xl:flex-row"
                    wire:submit="$dispatchSelf('connectToContainer')">
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
                    <x-forms.button type="submit">Connect</x-forms.button>
                </form>
            @else
                <div class="pt-4">No containers are running.</div>
            @endif
        </div>
    </div>
    <div class="mx-auto w-full">
        <livewire:project.shared.terminal />
    </div>
</div>
