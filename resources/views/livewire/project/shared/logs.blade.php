<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Logs | Coolify
    </x-slot>

    @if ($type === 'application')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.application.heading :application="$resource" wire:key="application-heading-logs" />
    @elseif ($type === 'database')
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" :query="$query"
            title="Logs" />
    @endif

    @php
        $totalContainers = collect($serverContainers)->flatten(1)->count();
        $functionalServers = $servers->filter(fn ($server) => $server->isFunctional());
        $logsUnavailable = $containersLoaded && (
            $servers->isEmpty()
            || $functionalServers->isEmpty()
            || $totalContainers === 0
        );
    @endphp

    @if (in_array($type, ['application', 'database', 'service'], true))
        <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
            <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
                @if ($type === 'application')
                    <x-application.configuration-sidebar :application="$resource" current-route="project.application.logs" />
                @elseif ($type === 'database')
                    <x-database.configuration-sidebar :database="$resource" current-route="project.database.logs" />
                @else
                    <x-service.configuration-sidebar :service="$resource" current-route="project.service.logs" />
                @endif
                <div class="min-w-0">
    @endif

    <div wire:loading.block wire:target="loadAllContainers" class="hidden w-full">
        <x-skeleton.logs />
    </div>

    <div class="w-full" x-init="if (! $wire.containersLoaded) { $wire.loadAllContainers() }"
        wire:loading.remove wire:target="loadAllContainers">
        @if (! $containersLoaded)
            <x-skeleton.logs />
        @elseif ($logsUnavailable)
            @if ($servers->isEmpty())
                <x-empty size="lg" title="Runtime logs unavailable"
                    description="Connect and validate a server before viewing resource logs."
                    icon-name="file-content" />
            @elseif ($functionalServers->isEmpty())
                <x-empty size="lg" title="Runtime logs unavailable"
                    description="No functional servers are available, so container logs cannot be loaded."
                    icon-name="file-content" />
            @elseif (count($serverErrors) > 0)
                <x-empty size="lg" title="Runtime logs unavailable"
                    description="Could not load containers: {{ collect($serverErrors)->first() }}"
                    icon-name="file-content" />
            @else
                <x-empty size="lg" title="Runtime logs unavailable"
                    description="No containers are running, so there are no runtime logs to show."
                    icon-name="file-content" />
            @endif
        @else
            <div class="flex flex-col gap-4">
                @foreach ($servers as $server)
                    @if (! $server->isFunctional())
                        <x-callout type="warning" title="Server unavailable">
                            {{ $server->name }} is not functional, so its container logs cannot be loaded.
                        </x-callout>
                    @elseif (isset($serverErrors[$server->id]))
                        <x-callout type="warning" title="Could not load containers">
                            {{ $server->name }}: {{ $serverErrors[$server->id] }}
                        </x-callout>
                    @elseif (isset($serverContainers[$server->id]) && count($serverContainers[$server->id]) > 0)
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
                    @endif
                @endforeach
            </div>
        @endif
    </div>
    @if (in_array($type, ['application', 'database', 'service'], true))
                </div>
            </div>
        </section>
    @endif
</div>
