<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class="sub-menu-item" target="_blank" href="{{ $service->documentation() }}"><span class="menu-item-label">Documentation</span>
                <x-external-link /></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.configuration', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.environment-variables', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Environment Variables</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.storages', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Persistent Storages</span></a>
            <a @class(['sub-menu-item', 'menu-item-active' => str($currentRoute)->startsWith('project.service.scheduled-tasks')]) {{ wireNavigate() }}
                href="{{ route('project.service.scheduled-tasks.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Scheduled Tasks</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.webhooks', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Webhooks</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.resource-operations', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Resource Operations</span></a>

            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.tags', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Tags</span></a>

            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.danger', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Danger Zone</span></a>
        </div>
        <div class="w-full">
            @if ($currentRoute === 'project.service.configuration')
                <livewire:project.service.stack-form :service="$service" />
                <h3>Services</h3>
                <div class="grid grid-cols-1 gap-2 pt-4 xl:grid-cols-1">
                    @if ($applications->isEmpty() && $databases->isEmpty())
                        <div class="p-4 text-sm text-neutral-500">
                            No services defined in this Docker Compose file.
                        </div>
                    @elseif($applications->isEmpty())
                        <div class="p-4 text-sm text-neutral-500">
                            No applications with domains defined. Only database services are available.
                        </div>
                    @endif

                    @foreach ($applications as $application)
                        <livewire:project.service.resource-card :service="$service" :resource="$application"
                            :parameters="$parameters" wire:key="service-application-card-{{ $application->id }}" />
                    @endforeach
                    @foreach ($databases as $database)
                        <livewire:project.service.resource-card :service="$service" :resource="$database"
                            :parameters="$parameters" wire:key="service-database-card-{{ $database->id }}" />
                    @endforeach
                </div>
            @elseif ($currentRoute === 'project.service.environment-variables')
                <livewire:project.shared.environment-variable.all :resource="$service" />
            @elseif ($currentRoute === 'project.service.storages')
                <div class="flex gap-2 items-center">
                    <h2>Storages</h2>
                </div>
                <div class="pb-4">Persistent storage to preserve data between deployments.</div>
                @foreach ($applications as $application)
                    <livewire:project.service.storage wire:key="application-{{ $application->id }}"
                        :resource="$application" />
                @endforeach
                @foreach ($databases as $database)
                    <livewire:project.service.storage wire:key="database-{{ $database->id }}" :resource="$database" />
                @endforeach
            @elseif ($currentRoute === 'project.service.scheduled-tasks.show')
                <livewire:project.shared.scheduled-task.all :resource="$service" />
            @elseif ($currentRoute === 'project.service.scheduled-tasks')
                <livewire:project.shared.scheduled-task.show />
            @elseif ($currentRoute === 'project.service.webhooks')
                <livewire:project.shared.webhooks :resource="$service" />
            @elseif ($currentRoute === 'project.service.resource-operations')
                <livewire:project.shared.resource-operations :resource="$service" />
            @elseif ($currentRoute === 'project.service.tags')
                <livewire:project.shared.tags :resource="$service" />
            @elseif ($currentRoute === 'project.service.danger')
                <livewire:project.shared.danger :resource="$service" />
            @endif
        </div>
    </div>
</div>
