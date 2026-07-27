<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Logs | Coolify
    </x-slot>

    <livewire:project.shared.configuration-checker :resource="$resource" />

    @if ($type === 'application')
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" :query="$query"
            title="Logs" />
    @endif

    @php
        $resourceStatus = data_get($resource, 'status', $status);
        $isRunning = str($resourceStatus)->contains('running');
    @endphp

    <div class="application-settings-form flex flex-col gap-6">
        <div wire:loading wire:target="loadAllContainers"
            class="application-settings-section-body flex min-h-32 items-center justify-center">
            <x-loading text="Loading containers" />
        </div>

        <div class="flex flex-col gap-4" x-init="$wire.loadAllContainers()" wire:loading.remove
            wire:target="loadAllContainers">
            @php
                $totalContainers = collect($serverContainers)->flatten(1)->count();
            @endphp

            @forelse ($servers as $server)
                @if ($server->isFunctional())
                    @if (isset($serverContainers[$server->id]) && count($serverContainers[$server->id]) > 0)
                        @if ($servers->count() > 1)
                            <div class="flex items-center gap-2 px-1">
                                <x-reicon name="servers" class="size-3.5 text-neutral-400 dark:text-fg-faint" />
                                <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">{{ $server->name }}</p>
                            </div>
                        @endif

                        @foreach ($serverContainers[$server->id] as $container)
                            <livewire:project.shared.get-logs
                                wire:key="{{ data_get($container, 'ID', uniqid()) }}" :server="$server"
                                :resource="$resource" :container="data_get($container, 'Names')"
                                :expandByDefault="$totalContainers === 1" />
                        @endforeach
                    @else
                        <x-empty size="sm" title="No running containers"
                            description="No containers are currently running on {{ $server->name }}.">
                            <x-slot:icon>
                                <x-reicon name="file-content" class="size-8" />
                            </x-slot:icon>
                        </x-empty>
                    @endif
                @else
                    <x-callout type="warning" title="Server unavailable">
                        {{ $server->name }} is not functional, so its container logs cannot be loaded.
                    </x-callout>
                @endif
            @empty
                <x-empty size="sm" title="No functional server"
                    description="Connect and validate a server before viewing resource logs.">
                    <x-slot:icon>
                        <x-reicon name="servers" class="size-8" />
                    </x-slot:icon>
                </x-empty>
            @endforelse
        </div>
    </div>
</div>
