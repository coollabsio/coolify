<div>
    @php
        [$statusType, $statusLabel] = match (true) {
            str($resource->status)->contains('running') => ['success', formatContainerStatus($resource->status)],
            str($resource->status)->contains(['starting', 'restarting', 'degraded']) => ['warning', formatContainerStatus($resource->status)],
            default => ['error', formatContainerStatus($resource->status)],
        };
        $resourceName = $resource->human_name
            ? Str::headline($resource->human_name)
            : Str::headline($resource->name);
    @endphp

    <div x-cloak x-show="viewMode === 'grid'"
        class="group flex min-w-0 flex-col overflow-hidden rounded-[10px] border border-neutral-200 bg-white transition-[border-color,background-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]">
        <div class="flex min-w-0 flex-1 items-start gap-3 p-4">
        <div
            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
            <x-reicon :name="$isDatabase ? 'database' : 'grid'" class="size-4" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex min-w-0 items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-black dark:text-fg">{{ $resourceName }}</div>
                    <div class="mt-0.5 truncate font-mono text-xs text-neutral-500 dark:text-fg-faint">
                        {{ $resource->image }}
                    </div>
                </div>
                <x-status-badge :status="$statusLabel" :type="$statusType" />
            </div>

            @if ($resource->configuration_required)
                <div class="mt-2">
                    <x-status-badge status="Configuration required" type="warning" />
                </div>
            @elseif ($resource->description)
                <p class="mt-2 line-clamp-2 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ $resource->description }}
                </p>
            @endif

        </div>
        </div>

        <div
            class="flex items-center justify-end gap-1 border-t border-neutral-200 bg-neutral-50 px-3 py-2 dark:border-white/[0.06] dark:bg-white/[0.02]">
        @if ($isDatabase && ($resource->isBackupSolutionAvailable() || $resource->is_migrated))
            <a class="icon-button" title="Service backups" aria-label="Service backups" {{ wireNavigate() }}
                href="{{ route('project.service.volume-backups.index', $parameters) }}">
                <x-reicon name="database" class="size-4" />
            </a>
        @endif
        @if ($isApplication && $resource->fqdn)
            @can('update', $service)
                <a class="icon-button" title="Manage domains" aria-label="Manage domains" {{ wireNavigate() }}
                    href="{{ route('project.service.domains', $parameters) }}">
                    <x-reicon name="globe" class="size-4" />
                </a>
            @endcan
        @endif
        <a class="icon-button" title="Resource settings" aria-label="Resource settings" {{ wireNavigate() }}
            href="{{ route('project.service.index', [...$parameters, 'stack_service_uuid' => $resource->uuid]) }}">
            <x-reicon name="settings" class="size-4" />
        </a>
        @if (str($resource->status)->contains('running'))
            @can('update', $service)
                <x-modal-confirmation
                    :title="$isApplication ? 'Confirm Service Application Restart?' : 'Confirm Service Database Restart?'"
                    buttonTitle="Restart" submitAction="restart" :actions="$isApplication
                        ? ['The selected service application will be unavailable during the restart.']
                        : ['This service database will be unavailable during the restart.']"
                    :confirmWithText="false" :confirmWithPassword="false"
                    :step2ButtonText="$isApplication ? 'Restart Service Container' : 'Restart Database'" />
            @endcan
        @endif
        </div>
    </div>

    <div x-cloak x-show="viewMode === 'table'"
        class="grid min-h-14 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 hover:bg-neutral-50 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_8rem_5rem] dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
        <div class="flex min-w-0 items-center gap-3">
            <div
                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                <x-reicon :name="$isDatabase ? 'database' : 'grid'" class="size-4" />
            </div>
            <div class="min-w-0">
                <div class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $resourceName }}</div>
            </div>
        </div>
        <div class="hidden truncate font-mono text-xs text-neutral-500 sm:block dark:text-fg-faint">
            {{ $resource->image }}
        </div>
        <div class="flex flex-wrap items-center justify-end gap-1 sm:contents">
            <div class="justify-self-start">
                <x-status-badge :status="$statusLabel" :type="$statusType" />
            </div>
            <div class="flex items-center justify-end gap-1">
                @if ($isDatabase && ($resource->isBackupSolutionAvailable() || $resource->is_migrated))
                    <a class="icon-button" title="Service backups" aria-label="Service backups" {{ wireNavigate() }}
                        href="{{ route('project.service.volume-backups.index', $parameters) }}">
                        <x-reicon name="database" class="size-4" />
                    </a>
                @endif
                @if ($isApplication && $resource->fqdn)
                    @can('update', $service)
                        <a class="icon-button" title="Manage domains" aria-label="Manage domains" {{ wireNavigate() }}
                            href="{{ route('project.service.domains', $parameters) }}">
                            <x-reicon name="globe" class="size-4" />
                        </a>
                    @endcan
                @endif
                <a class="icon-button" title="Resource settings" aria-label="Resource settings" {{ wireNavigate() }}
                    href="{{ route('project.service.index', [...$parameters, 'stack_service_uuid' => $resource->uuid]) }}">
                    <x-reicon name="settings" class="size-4" />
                </a>
            </div>
        </div>
    </div>
</div>
