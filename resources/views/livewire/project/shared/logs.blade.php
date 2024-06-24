<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Logs | Coolify
    </x-slot>
    <livewire:project.shared.configuration-checker :resource="$resource" />
    @if ($type === 'application')
        <h1>Logs</h1>
        <livewire:project.application.heading :application="$resource" />
        <div class="pt-4">
            <h2>Logs</h2>
            <div class="subtitle">Here you can see the logs of the application.</div>
            <div class="pt-2" wire:loading wire:target="loadContainers">
                Loading containers...
            </div>
            @forelse ($servers as $server)
                <div class="py-2">
                    <h2 wire:loading.remove x-init="$wire.loadContainers({{ $server->id }})">Server: {{ $server->name }}</h2>
                    <div wire:loading.remove wire:target="loadContainers">
                        @forelse (data_get($server,'containers',[]) as $container)
                            <livewire:project.shared.get-logs :server="$server" :resource="$resource" :container="data_get($container, 'Names')" />
                        @empty
                            <div class="pt-2">No containers are running on server: {{ $server->name }}</div>
                        @endforelse
                    </div>
                </div>
            @empty
                <div>No functional server found for the application.</div>
            @endforelse
        </div>
    @elseif ($type === 'database')
        <h1>Logs</h1>
        <livewire:project.database.heading :database="$resource" />
        <div class="pt-4">
            @forelse ($containers as $container)
                @if ($loop->first)
                    <h2 class="pb-4">Logs</h2>
                @endif
                @if (data_get($servers, '0'))
                    <livewire:project.shared.get-logs :server="data_get($servers, '0')" :resource="$resource" :container="$container" />
                @else
                    <div> No functional server found for the database.</div>
                @endif
            @empty
                <div class="pt-2">No containers are running.</div>
            @endforelse
        </div>
    @elseif ($type === 'service')
        <div>
            @forelse ($containers as $container)
                @if ($loop->first)
                    <h2 class="pb-4">Logs</h2>
                @endif
                @if (data_get($servers, '0'))
                    <livewire:project.shared.get-logs :server="data_get($servers, '0')" :resource="$resource" :container="$container" />
                @else
                    <div> No functional server found for the service.</div>
                @endif
            @empty
                <div class="pt-2">No containers are running.</div>
            @endforelse
        </div>
    @endif
</div>
