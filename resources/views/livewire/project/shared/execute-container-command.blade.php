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

    @if(!$hasShell)
        <div class="flex items-center justify-center w-full py-4 mx-auto">
            <div class="p-4 w-full rounded border dark:bg-coolgray-100 dark:border-coolgray-300">
                <div class="flex flex-col items-center justify-center space-y-4">
                    <svg class="w-12 h-12 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div class="text-center">
                        <h3 class="text-lg font-medium">Terminal Not Available</h3>
                        <p class="mt-2 text-sm text-gray-500">No shell (bash/sh) is available in this container. Please ensure either bash or sh is installed to use the terminal.</p>
                    </div>
                </div>
            </div>
        </div>
    @else
        @if ($type === 'server')
            <form class="w-full" wire:submit="$dispatchSelf('connectToServer')" wire:init="$dispatchSelf('connectToServer')">
                <x-forms.button class="w-full" type="submit">Reconnect</x-forms.button>
            </form>
            <div class="mx-auto w-full">
                <livewire:project.shared.terminal />
            </div>
        @else
            @if (count($containers) === 0)
                <div class="pt-4">No containers are running.</div>
            @else
                @if (count($containers) === 1)
                    <form class="w-full pt-4" wire:submit="$dispatchSelf('connectToContainer')"
                        wire:init="$dispatchSelf('connectToContainer')">
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
                        <x-forms.button class="w-full" type="submit">Connect</x-forms.button>
                    </form>
                @endif
                <div class="mx-auto w-full">
                    <livewire:project.shared.terminal />
                </div>
            @endif
        @endif
    @endif
</div>
