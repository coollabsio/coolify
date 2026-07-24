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
            @if ($isApplication)
                @php
                    $domainCount = filled($resource->fqdn)
                        ? collect(explode(',', $resource->fqdn))->map(fn ($d) => trim($d))->filter()->count()
                        : 0;
                @endphp
                <span class="flex flex-wrap items-center gap-1 text-xs dark:text-neutral-400">
                    @if ($domainCount === 0)
                        No domains set
                    @elseif ($domainCount === 1)
                        1 domain set
                    @else
                        {{ $domainCount }} domains set
                    @endif
                    <span class="opacity-50">·</span>
                    <a class="underline dark:text-warning text-coollabs" {{ wireNavigate() }}
                        href="{{ route('project.service.domains', [
                            'project_uuid' => data_get($parameters, 'project_uuid'),
                            'environment_uuid' => data_get($parameters, 'environment_uuid'),
                            'service_uuid' => data_get($parameters, 'service_uuid'),
                        ]) }}">
                        Manage
                    </a>
                </span>
            @endif
            <div @class(['pt-2' => $isApplication])>
                @if (str($resource->status)->contains('running'))
                    <x-status-badge status="{{ formatContainerStatus($resource->status) }}" type="success" />
                @elseif (str($resource->status)->contains(['starting', 'restarting', 'degraded']))
                    <x-status-badge status="{{ formatContainerStatus($resource->status) }}" type="warning" />
                @else
                    <x-status-badge status="{{ formatContainerStatus($resource->status) }}" type="error" />
                @endif
            </div>
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
