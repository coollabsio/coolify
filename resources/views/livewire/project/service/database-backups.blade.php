<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} >
        {{ data_get_str($serviceDatabase, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <x-service.configuration-sidebar :service="$service"
                current-route="project.service.volume-backups.index" />

            <div class="min-w-0">
                @if ($backup)
                    <div class="flex min-w-0 flex-col gap-6">
                        <div class="flex min-w-0 flex-col gap-4">
                            <div>
                                <a class="inline-flex items-center gap-1.5 text-xs text-neutral-500 hover:text-neutral-900 dark:text-fg-dim dark:hover:text-fg"
                                    {{ wireNavigate() }}
                                    href="{{ route('project.service.volume-backups.index', collect($parameters)->except(['stack_service_uuid', 'backup_uuid'])->all()) }}">
                                    <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                                    Back to backups
                                </a>
                                <h1 class="mt-2 text-xl font-semibold text-neutral-950 dark:text-fg">
                                    {{ $serviceDatabase->human_name ?: $serviceDatabase->name }} backup
                                </h1>
                                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                                    {{ $backup->frequency }} schedule
                                </p>
                            </div>

                            <x-backup-tabs context="service" :parameters="$backupParameters" :section="$section" />
                        </div>

                        @if ($section === 'executions')
                            <livewire:project.database.backup-executions :backup="$backup"
                                :database="$serviceDatabase" />
                        @else
                            <livewire:project.database.backup-edit :backup="$backup"
                                :available-s3-storages="$s3s" :status="data_get($serviceDatabase, 'status')"
                                :section="$section"
                                wire:key="service-database-backup-{{ $backup->uuid }}-{{ $section }}" />
                        @endif
                    </div>
                @else
                    <section class="application-settings-section">
                        <div class="application-settings-section-header">
                            <div>
                                <h2>Scheduled backups</h2>
                                <p>Automate backups for {{ $serviceDatabase->human_name ?: $serviceDatabase->name }}.</p>
                            </div>
                            @if (filled($serviceDatabase->custom_type) || ! $serviceDatabase->is_migrated)
                                @can('update', $serviceDatabase)
                                    <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                        <livewire:project.database.create-scheduled-backup
                                            :database="$serviceDatabase" />
                                    </x-modal-input>
                                @endcan
                            @endif
                        </div>
                        <div class="application-settings-section-body p-0!">
                            <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </section>
</div>
