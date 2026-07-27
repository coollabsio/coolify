<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} >
        {{ data_get_str($serviceDatabase, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />

    <section class="application-settings-workspace mt-8 w-full max-w-[1180px] xl:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
            @if ($backup)
                <x-backup-sidebar context="service" :parameters="$backupParameters" :section="$section" />
            @else
                <x-service-database.sidebar :parameters="$parameters" :serviceDatabase="$serviceDatabase"
                    :isImportSupported="$isImportSupported" />
            @endif

            <div class="min-w-0 xl:mt-3">
                @if ($backup)
                    @if ($section === 'executions')
                        <livewire:project.database.backup-executions :backup="$backup"
                            :database="$serviceDatabase" />
                    @else
                        <livewire:project.database.backup-edit :backup="$backup"
                            :available-s3-storages="$s3s" :status="data_get($serviceDatabase, 'status')"
                            :section="$section"
                            wire:key="service-database-backup-{{ $backup->uuid }}-{{ $section }}" />
                    @endif
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
