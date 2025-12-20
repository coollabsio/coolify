<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > {{ __('logs.title') }} | Coolify
    </x-slot>
    <livewire:project.shared.configuration-checker :resource="$resource" />
    @if ($type === 'application')
        <h1>{{ __('logs.title') }}</h1>
        <livewire:project.application.heading :application="$resource" />
        <div>
            <h2>{{ __('logs.title') }}</h2>
            @if (str($status)->contains('exited'))
                <div class="pt-4">{{ __('logs.resource_not_running') }}</div>
            @else
                <div class="pt-2" wire:loading wire:target="loadAllContainers">
                    {{ __('logs.loading_containers') }}
                </div>
                <div x-init="$wire.loadAllContainers()" wire:loading.remove wire:target="loadAllContainers">
                    @forelse ($servers as $server)
                        <div class="py-2">
                            <h4>{{ __('logs.server_label') }} {{ $server->name }}</h4>
                            @if ($server->isFunctional())
                                @if (isset($serverContainers[$server->id]) && count($serverContainers[$server->id]) > 0)
                                    @php
                                        $totalContainers = collect($serverContainers)->flatten(1)->count();
                                    @endphp
                                    @foreach ($serverContainers[$server->id] as $container)
                                        <livewire:project.shared.get-logs
                                            wire:key="{{ data_get($container, 'ID', uniqid()) }}" :server="$server"
                                            :resource="$resource" :container="data_get($container, 'Names')"
                                            :expandByDefault="$totalContainers === 1" />
                                    @endforeach
                                @else
                                    <div class="pt-2">{{ __('logs.no_containers_on_server') }} {{ $server->name }}</div>
                                @endif
                            @else
                                <div class="pt-2">{{ __('logs.server_not_functional', ['name' => $server->name]) }}</div>
                            @endif
                        </div>
                    @empty
                        <div>{{ __('logs.no_functional_server_application') }}</div>
                    @endforelse
                </div>
            @endif
        </div>
    @elseif ($type === 'database')
        <h1>{{ __('logs.title') }}</h1>
        <livewire:project.database.heading :database="$resource" />
        <div>
            <h2>{{ __('logs.title') }}</h2>
            @if (str($status)->contains('exited'))
                <div class="pt-4">{{ __('logs.resource_not_running') }}</div>
            @else
                <div class="pt-2" wire:loading wire:target="loadAllContainers">
                    {{ __('logs.loading_containers') }}
                </div>
                <div x-init="$wire.loadAllContainers()" wire:loading.remove wire:target="loadAllContainers">
                    @forelse ($containers as $container)
                        @if (data_get($servers, '0'))
                            <livewire:project.shared.get-logs wire:key='{{ $container }}' :server="data_get($servers, '0')"
                                :resource="$resource" :container="$container"
                                :expandByDefault="count($containers) === 1" />
                        @else
                            <div>{{ __('logs.no_functional_server_database') }}</div>
                        @endif
                    @empty
                        <div class="pt-2">{{ __('logs.no_containers_running') }}</div>
                    @endforelse
                </div>
            @endif
        </div>
    @elseif ($type === 'service')
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" :query="$query" title="{{ __('logs.title') }}" />
        <div>
            <h2>{{ __('logs.title') }}</h2>
            @if (str($status)->contains('exited'))
                <div class="pt-4">{{ __('logs.resource_not_running') }}</div>
            @else
                <div class="pt-2" wire:loading wire:target="loadAllContainers">
                    {{ __('logs.loading_containers') }}
                </div>
                <div x-init="$wire.loadAllContainers()" wire:loading.remove wire:target="loadAllContainers">
                    @forelse ($containers as $container)
                        @if (data_get($servers, '0'))
                            <livewire:project.shared.get-logs wire:key='{{ $container }}' :server="data_get($servers, '0')"
                                :resource="$resource" :container="$container"
                                :expandByDefault="count($containers) === 1" />
                        @else
                            <div>{{ __('logs.no_functional_server_service') }}</div>
                        @endif
                    @empty
                        <div class="pt-2">{{ __('logs.no_containers_running') }}</div>
                    @endforelse
                </div>
            @endif
        </div>
    @endif
</div>
