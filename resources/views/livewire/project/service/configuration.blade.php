<div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'service-stack' }" x-init="$wire.check_status" wire:poll.5000ms="check_status">
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>
    <livewire:project.service.navbar :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex flex-col h-full gap-8 pt-6 sm:flex-row">
        <div class="flex flex-col items-start gap-2 min-w-fit">
            <a class="menu-item sm:min-w-fit" target="_blank" href="{{ $service->documentation() }}">Documentation
                <x-external-link /></a>
            <a class="menu-item sm:min-w-fit" :class="activeTab === 'service-stack' && 'menu-item-active'"
                @click.prevent="activeTab = 'service-stack';
                window.location.hash = 'service-stack'"
                href="#">Service Stack</a>
            <a class="menu-item sm:min-w-fit" :class="activeTab === 'environment-variables' && 'menu-item-active'"
                @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                href="#">Environment
                Variables</a>
            <a class="menu-item sm:min-w-fit" :class="activeTab === 'storages' && 'menu-item-active'"
                @click.prevent="activeTab = 'storages';
                window.location.hash = 'storages'"
                href="#">Storages</a>
            <a class="menu-item" :class="activeTab === 'scheduled-tasks' && 'menu-item-active'"
                @click.prevent="activeTab = 'scheduled-tasks'; window.location.hash = 'scheduled-tasks'"
                href="#">Scheduled Tasks
            </a>
            <a class="menu-item sm:min-w-fit" :class="activeTab === 'execute-command' && 'menu-item-active'"
                @click.prevent="activeTab = 'execute-command';
                window.location.hash = 'execute-command'"
                href="#">Execute Command</a>
            <a class="menu-item sm:min-w-fit" :class="activeTab === 'logs' && 'menu-item-active'"
                @click.prevent="activeTab = 'logs';
                window.location.hash = 'logs'"
                href="#">Logs</a>
            <a class="menu-item sm:min-w-fit" :class="activeTab === 'webhooks' && 'menu-item-active'"
                @click.prevent="activeTab = 'webhooks'; window.location.hash = 'webhooks'" href="#">Webhooks
            </a>
            <a class="menu-item sm:min-w-fit" :class="activeTab === 'resource-operations' && 'menu-item-active'"
                @click.prevent="activeTab = 'resource-operations'; window.location.hash = 'resource-operations'"
                href="#">Resource Operations
            </a>
            <a class="menu-item sm:min-w-fit" :class="activeTab === 'tags' && 'menu-item-active'"
                @click.prevent="activeTab = 'tags'; window.location.hash = 'tags'" href="#">Tags
            </a>
            <a class="menu-item sm:min-w-fit" :class="activeTab === 'danger' && 'menu-item-active'"
                @click.prevent="activeTab = 'danger';
                window.location.hash = 'danger'"
                href="#">Danger Zone
            </a>
        </div>
        <div class="w-full">
            <div x-cloak x-show="activeTab === 'service-stack'">
                <livewire:project.service.stack-form :service="$service" />
                <h3>Services</h3>
                <div class="grid grid-cols-1 gap-2 pt-4 xl:grid-cols-1">
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
                                            <x-modal-input title="Edit Domains">
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
                                        href="{{ route('project.service.index', [...$parameters, 'stack_service_uuid' => $application->uuid]) }}">
                                        Settings
                                    </a>
                                    @if (str($application->status)->contains('running'))
                                        <x-modal-confirmation action="restartApplication({{ $application->id }})"
                                            isErrorButton buttonTitle="Restart">
                                            This application will be unavailable during the restart. <br>Please think
                                            again.
                                        </x-modal-confirmation>
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
                                    <a class="mx-4 text-xs font-bold hover:underline"
                                        href="{{ route('project.service.index', [...$parameters, 'stack_service_uuid' => $database->uuid]) }}">
                                        Settings
                                    </a>
                                    @if (str($database->status)->contains('running'))
                                        <x-modal-confirmation action="restartDatabase({{ $database->id }})"
                                            isErrorButton buttonTitle="Restart">
                                            This database will be unavailable during the restart. <br>Please think
                                            again.
                                        </x-modal-confirmation>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div x-cloak x-show="activeTab === 'storages'">
                <div class="flex items-center gap-2">
                    <h2>Storages</h2>
                </div>
                <div class="pb-4">Persistent storage to preserve data between deployments.</div>
                <div class="pb-4 dark:text-warning text-coollabs">If you would like to add a volume, you must add it to
                    your compose file (General tab).</div>
                @foreach ($applications as $application)
                    <livewire:project.service.storage wire:key="application-{{ $application->id }}"
                        :resource="$application" />
                @endforeach
                @foreach ($databases as $database)
                    <livewire:project.service.storage wire:key="database-{{ $database->id }}" :resource="$database" />
                @endforeach
            </div>
            <div x-cloak x-show="activeTab === 'scheduled-tasks'">
                <livewire:project.shared.scheduled-task.all :resource="$service" />
            </div>
            <div x-cloak x-show="activeTab === 'webhooks'">
                <livewire:project.shared.webhooks :resource="$service" />
            </div>
            <div x-cloak x-show="activeTab === 'logs'">
                <livewire:project.shared.logs :resource="$service" />
            </div>
            <div x-cloak x-show="activeTab === 'execute-command'">
                <livewire:project.shared.execute-container-command :resource="$service" />
            </div>
            <div x-cloak x-show="activeTab === 'environment-variables'">
                <livewire:project.shared.environment-variable.all :resource="$service" />
            </div>
            <div x-cloak x-show="activeTab === 'resource-operations'">
                <livewire:project.shared.resource-operations :resource="$service" />
            </div>
            <div x-cloak x-show="activeTab === 'tags'">
                <livewire:project.shared.tags :resource="$service" />
            </div>
            <div x-cloak x-show="activeTab === 'danger'">
                <livewire:project.shared.danger :resource="$service" />
            </div>
        </div>
    </div>
</div>
