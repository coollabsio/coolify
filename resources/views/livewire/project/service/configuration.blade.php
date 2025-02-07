<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>
    <livewire:project.service.navbar :service="$service" :parameters="$parameters" :query="$query" />

    <div class="flex flex-col h-full gap-8 pt-6 sm:flex-row">
        <div class="flex flex-col items-start gap-2 min-w-fit">
            <a class="menu-item sm:min-w-fit" target="_blank" href="{{ $service->documentation() }}">Documentation
                <x-external-link /></a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.service.configuration', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"
                wire:navigate>General</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.service.environment-variables', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"
                wire:navigate>Environment Variables</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.service.storages', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"
                wire:navigate>Persistent Storages</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.service.scheduled-tasks.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"
                wire:navigate>Scheduled Tasks</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.service.webhooks', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"
                wire:navigate>Webhooks</a>
            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.service.resource-operations', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"
                wire:navigate>Resource Operations</a>

            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.service.tags', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"
                wire:navigate>Tags</a>

            <a class='menu-item' wire:current.exact="menu-item-active"
                href="{{ route('project.service.danger', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid]) }}"
                wire:navigate>Danger Zone</a>
        </div>
        <div class="w-full">
            @if ($currentRoute === 'project.service.configuration')
                <livewire:project.service.stack-form :service="$service" />
                <h3>Services</h3>
                <div class="grid grid-cols-1 gap-2 pt-4 xl:grid-cols-1" wire:poll.10s="check_status">
                    @foreach ($applications as $application)
                        <div @class([
                            'border-l border-dashed border-red-500 ' => str(
                                $application->status)->contains(['exited']),
                            'border-l border-dashed border-success' => str(
                                $application->status)->contains(['running']),
                            'border-l border-dashed border-warning' => str(
                                $application->status)->contains(['starting']),
                            'flex gap-2 box-without-bg-without-border dark:bg-coolgray-100 bg-white dark:hover:text-neutral-300 group',
                        ])>
                            <div class="flex flex-row w-full">
                                <div class="flex flex-col flex-1">
                                    <div class="pb-2">
                                        @if ($application->human_name)
                                            {{ Str::headline($application->human_name) }}
                                        @else
                                            {{ Str::headline($application->name) }}
                                        @endif
                                        <span class="text-xs">({{ $application->image }})</span>
                                    </div>
                                    @if ($application->configuration_required)
                                        <span class="text-xs text-error">(configuration required)</span>
                                    @endif
                                    @if ($application->description)
                                        <span class="text-xs">{{ Str::limit($application->description, 60) }}</span>
                                    @endif
                                    @if ($application->fqdn)
                                        <span class="flex gap-1 text-xs">{{ Str::limit($application->fqdn, 60) }}
                                            <x-modal-input title="Edit Domains" :closeOutside="false">
                                                <x-slot:content>
                                                    <span class="cursor-pointer">
                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                            class="w-4 h-4 dark:text-warning text-coollabs"
                                                            viewBox="0 0 24 24">
                                                            <g fill="none" stroke="currentColor"
                                                                stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2">
                                                                <path
                                                                    d="m12 15l8.385-8.415a2.1 2.1 0 0 0-2.97-2.97L9 12v3h3zm4-10l3 3" />
                                                                <path d="M9 7.07A7 7 0 0 0 10 21a7 7 0 0 0 6.929-6" />
                                                            </g>
                                                        </svg>

                                                    </span>
                                                </x-slot:content>
                                                <livewire:project.service.edit-domain
                                                    applicationId="{{ $application->id }}"
                                                    wire:key="edit-domain-{{ $application->id }}" />
                                            </x-modal-input>
                                        </span>
                                    @endif
                                    <div class="pt-2 text-xs">{{ $application->status }}</div>
                                </div>
                                <div class="flex items-center px-4">
                                    <a class="mx-4 text-xs font-bold hover:underline"
                                        href="{{ route('project.service.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid, 'stack_service_uuid' => $application->uuid]) }}"
                                        wire:navigate>
                                        Settings
                                    </a>
                                    @if (str($application->status)->contains('running'))
                                        <x-modal-confirmation title="Confirm Service Application Restart?"
                                            buttonTitle="Restart"
                                            submitAction="restartApplication({{ $application->id }})" :actions="[
                                                'The selected service application will be unavailable during the restart.',
                                                'If the service application is currently in use data could be lost.',
                                            ]"
                                            :confirmWithText="false" :confirmWithPassword="false"
                                            step2ButtonText="Restart Service Container" />
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                    @foreach ($databases as $database)
                        <div @class([
                            'border-l border-dashed border-red-500' => str($database->status)->contains(
                                ['exited']),
                            'border-l border-dashed border-success' => str($database->status)->contains(
                                ['running']),
                            'border-l border-dashed border-warning' => str($database->status)->contains(
                                ['restarting']),
                            'flex gap-2 box-without-bg-without-border dark:bg-coolgray-100 bg-white dark:hover:text-neutral-300 group',
                        ])>
                            <div class="flex flex-row w-full">
                                <div class="flex flex-col flex-1">
                                    <div class="pb-2">
                                        @if ($database->human_name)
                                            {{ Str::headline($database->human_name) }}
                                        @else
                                            {{ Str::headline($database->name) }}
                                        @endif
                                        <span class="text-xs">({{ $database->image }})</span>
                                    </div>
                                    @if ($database->configuration_required)
                                        <span class="text-xs text-error">(configuration required)</span>
                                    @endif
                                    @if ($database->description)
                                        <span class="text-xs">{{ Str::limit($database->description, 60) }}</span>
                                    @endif
                                    <div class="text-xs">{{ $database->status }}</div>
                                </div>
                                <div class="flex items-center px-4">
                                    @if ($database->isBackupSolutionAvailable())
                                        <a class="mx-4 text-xs font-bold hover:underline"
                                            href="{{ route('project.service.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid, 'stack_service_uuid' => $database->uuid]) }}#backups"
                                            wire:navigate>
                                            Backups
                                        </a>
                                    @endif
                                    <a class="mx-4 text-xs font-bold hover:underline"
                                        href="{{ route('project.service.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'service_uuid' => $service->uuid, 'stack_service_uuid' => $database->uuid]) }}"
                                        wire:navigate>
                                        Settings
                                    </a>
                                    @if (str($database->status)->contains('running'))
                                        <x-modal-confirmation title="Confirm Service Database Restart?"
                                            buttonTitle="Restart" submitAction="restartDatabase({{ $database->id }})"
                                            :actions="[
                                                'This service database will be unavailable during the restart.',
                                                'If the service database is currently in use data could be lost.',
                                            ]" :confirmWithText="false" :confirmWithPassword="false"
                                            step2ButtonText="Restart Database" />
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @elseif ($currentRoute === 'project.service.environment-variables')
                <livewire:project.shared.environment-variable.all :resource="$service" />
            @elseif ($currentRoute === 'project.service.storages')
                <div class="flex gap-2 items-center">
                    <h2>Storages</h2>
                </div>
                <div class="pb-4">Persistent storage to preserve data between deployments.</div>
                <div class="pb-4 dark:text-warning text-coollabs">If you would like to add a volume, you must add it to
                    your compose file (Service Stack tab).</div>
                @foreach ($applications as $application)
                    <livewire:project.service.storage wire:key="application-{{ $application->id }}"
                        :resource="$application" />
                @endforeach
                @foreach ($databases as $database)
                    <livewire:project.service.storage wire:key="database-{{ $database->id }}" :resource="$database" />
                @endforeach
            @elseif ($currentRoute === 'project.service.scheduled-tasks.show')
                <livewire:project.shared.scheduled-task.all :resource="$service" />
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
