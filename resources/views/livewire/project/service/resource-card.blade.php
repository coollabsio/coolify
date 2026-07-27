<div
    class="group flex min-w-0 flex-col overflow-hidden rounded-[10px] border border-neutral-200 bg-white transition-[border-color,background-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]">
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

            @if ($isApplication && $resource->fqdn)
                <div class="mt-2 flex min-w-0 items-center gap-1.5">
                    <span class="min-w-0 truncate text-xs text-neutral-500 dark:text-fg-dim">
                        {{ $resource->fqdn }}
                    </span>
                    @can('update', $service)
                        <x-modal-input title="Edit Domains" :closeOutside="false">
                            <x-slot:content>
                                <button type="button" class="icon-button shrink-0" title="Edit domains">
                                    <x-reicon name="settings" class="size-3.5" />
                                </button>
                            </x-slot:content>
                            <livewire:project.service.edit-domain applicationId="{{ $resource->id }}"
                                wire:key="edit-domain-{{ $resource->id }}" />
                        </x-modal-input>
                    @endcan
                </div>
            @endif
        </div>
    </div>

    <div
        class="flex items-center justify-end gap-1 border-t border-neutral-200 bg-neutral-50 px-3 py-2 dark:border-white/[0.06] dark:bg-white/[0.02]">
        @if ($isDatabase && ($resource->isBackupSolutionAvailable() || $resource->is_migrated))
            <a class="button" {{ wireNavigate() }}
                href="{{ route('project.service.database.backups', [...$parameters, 'stack_service_uuid' => $resource->uuid]) }}">
                Backups
            </a>
        @endif
        <a class="button" {{ wireNavigate() }}
            href="{{ route('project.service.index', [...$parameters, 'stack_service_uuid' => $resource->uuid]) }}">
            Settings
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
