<div @class([
    'border-l border-dashed border-red-500' => str($resource->status)->contains(['exited']),
    'border-l border-dashed border-success' => str($resource->status)->contains(['running']),
    'border-l border-dashed border-warning' => str($resource->status)->contains(['starting', 'restarting']),
    'flex gap-2 box-without-bg-without-border dark:bg-coolgray-100 bg-white dark:hover:text-neutral-300 group',
])>
    <div class="flex flex-row w-full">
        <div class="flex flex-col flex-1">
            <div class="pb-2">
                @if ($resource->human_name)
                    {{ Str::headline($resource->human_name) }}
                @else
                    {{ Str::headline($resource->name) }}
                @endif
                <span class="text-xs">({{ $resource->image }})</span>
            </div>
            @if ($resource->configuration_required)
                <span class="text-xs text-error">(configuration required)</span>
            @endif
            @if ($resource->description)
                <span class="text-xs">{{ Str::limit($resource->description, 60) }}</span>
            @endif
            @if ($isApplication && $resource->fqdn)
                <span class="flex gap-1 text-xs">{{ Str::limit($resource->fqdn, 60) }}
                    @can('update', $service)
                        <x-modal-input title="Edit Domains" :closeOutside="false">
                            <x-slot:content>
                                <span class="cursor-pointer">
                                    <svg xmlns="http://www.w3.org/2000/svg"
                                        class="w-4 h-4 dark:text-warning text-coollabs"
                                        viewBox="0 0 24 24">
                                        <g fill="none" stroke="currentColor" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="2">
                                            <path d="m12 15l8.385-8.415a2.1 2.1 0 0 0-2.97-2.97L9 12v3h3zm4-10l3 3" />
                                            <path d="M9 7.07A7 7 0 0 0 10 21a7 7 0 0 0 6.929-6" />
                                        </g>
                                    </svg>
                                </span>
                            </x-slot:content>
                            <livewire:project.service.edit-domain applicationId="{{ $resource->id }}"
                                wire:key="edit-domain-{{ $resource->id }}" />
                        </x-modal-input>
                    @endcan
                </span>
            @endif
            <div @class(['pt-2' => $isApplication, 'text-xs'])>{{ formatContainerStatus($resource->status) }}</div>
        </div>
        <div class="flex items-center px-4">
            @if ($isDatabase && ($resource->isBackupSolutionAvailable() || $resource->is_migrated))
                <a class="mx-4 text-xs font-bold hover:underline" {{ wireNavigate() }}
                    href="{{ route('project.service.database.backups', [...$parameters, 'stack_service_uuid' => $resource->uuid]) }}">
                    Backups
                </a>
            @endif
            <a class="mx-4 text-xs font-bold hover:underline" {{ wireNavigate() }}
                href="{{ route('project.service.index', [...$parameters, 'stack_service_uuid' => $resource->uuid]) }}">
                Settings
            </a>
            @if (str($resource->status)->contains('running'))
                @can('update', $service)
                    <x-modal-confirmation :title="$isApplication ? 'Confirm Service Application Restart?' : 'Confirm Service Database Restart?'"
                        buttonTitle="Restart" submitAction="restart" :actions="$isApplication
                            ? [
                                'The selected service application will be unavailable during the restart.',
                                'If the service application is currently in use data could be lost.',
                            ]
                            : [
                                'This service database will be unavailable during the restart.',
                                'If the service database is currently in use data could be lost.',
                            ]"
                        :confirmWithText="false" :confirmWithPassword="false"
                        :step2ButtonText="$isApplication ? 'Restart Service Container' : 'Restart Database'" />
                @endcan
            @endif
        </div>
    </div>
</div>
