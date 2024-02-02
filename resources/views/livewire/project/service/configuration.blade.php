<div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'service-stack' }" x-init="$wire.checkStatus">
    <livewire:project.service.navbar :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex h-full pt-6">
        <div class="flex flex-col items-start gap-4 min-w-fit">
            <a target="_blank" href="{{ $service->documentation() }}">Documentation <x-external-link /></a>
            <a :class="activeTab === 'service-stack' && 'text-white'"
                @click.prevent="activeTab = 'service-stack';
                window.location.hash = 'service-stack'"
                href="#">Service Stack</a>
            <a :class="activeTab === 'environment-variables' && 'text-white'"
                @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                href="#">Environment
                Variables</a>
            <a :class="activeTab === 'storages' && 'text-white'"
                @click.prevent="activeTab = 'storages';
                window.location.hash = 'storages'"
                href="#">Storages</a>
            <a :class="activeTab === 'execute-command' && 'text-white'"
                @click.prevent="activeTab = 'execute-command';
                window.location.hash = 'execute-command'"
                href="#">Execute Command</a>
            <a :class="activeTab === 'logs' && 'text-white'"
                @click.prevent="activeTab = 'logs';
                window.location.hash = 'logs'"
                href="#">Logs</a>
            <a :class="activeTab === 'webhooks' && 'text-white'"
                @click.prevent="activeTab = 'webhooks'; window.location.hash = 'webhooks'" href="#">Webhooks
            </a>
            <a :class="activeTab === 'resource-operations' && 'text-white'"
                @click.prevent="activeTab = 'resource-operations'; window.location.hash = 'resource-operations'"
                href="#">Resource Operations
            </a>
            <a :class="activeTab === 'tags' && 'text-white'"
                @click.prevent="activeTab = 'tags'; window.location.hash = 'tags'" href="#">Tags
            </a>
            <a :class="activeTab === 'danger' && 'text-white'"
                @click.prevent="activeTab = 'danger';
                window.location.hash = 'danger'"
                href="#">Danger Zone
            </a>
        </div>
        <div class="w-full pl-8">
            <div x-cloak x-show="activeTab === 'service-stack'">
                <livewire:project.service.stack-form :service="$service" />
                <div class="grid grid-cols-1 gap-2 pt-4 xl:grid-cols-1">
                    @foreach ($applications as $application)
                        <div @class([
                            'border-l border-dashed border-red-500' => Str::of(
                                $application->status)->contains(['exited']),
                            'border-l border-dashed border-success' => Str::of(
                                $application->status)->contains(['running']),
                            'border-l border-dashed border-warning' => Str::of(
                                $application->status)->contains(['starting']),
                            'flex gap-2 box-without-bg bg-coolgray-100 hover:text-neutral-300 group',
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
                                        <span class="text-xs">{{ Str::limit($application->fqdn, 60) }}</span>
                                    @endif
                                    <div class="text-xs">{{ $application->status }}</div>
                                </div>
                                <div class="flex items-center px-4">
                                    <a class="flex flex-col flex-1 group-hover:text-white hover:no-underline"
                                        href="{{ route('project.service.index', [...$parameters, 'service_name' => $application->name]) }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon hover:text-warning"
                                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                            <path
                                                d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" />
                                            <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />
                                        </svg>
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                    @foreach ($databases as $database)
                        <div @class([
                            'border-l border-dashed border-red-500' => Str::of(
                                $database->status)->contains(['exited']),
                            'border-l border-dashed border-success' => Str::of(
                                $database->status)->contains(['running']),
                            'border-l border-dashed border-warning' => Str::of(
                                $database->status)->contains(['restarting']),
                            'flex gap-2 box-without-bg bg-coolgray-100 hover:text-neutral-300 group',
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
                                    <a class="flex flex-col flex-1 group-hover:text-white hover:no-underline"
                                        href="{{ route('project.service.index', [...$parameters, 'service_name' => $database->name]) }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon hover:text-warning"
                                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                            <path
                                                d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" />
                                            <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />
                                        </svg>
                                    </a>
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
                <span class="text-warning">Please modify storage layout in your Docker Compose file.</span>
                @foreach ($applications as $application)
                    <livewire:project.service.storage wire:key="application-{{ $application->id }}"
                        :resource="$application" />
                @endforeach
                @foreach ($databases as $database)
                    <livewire:project.service.storage wire:key="database-{{ $database->id }}" :resource="$database" />
                @endforeach
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
