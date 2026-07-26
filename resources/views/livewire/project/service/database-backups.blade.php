<div>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex flex-col h-full gap-2 md:gap-8 md:flex-row">
        @if ($backup)
            <div class="sub-menu-wrapper">
                <a class="sub-menu-item" {{ wireNavigate() }}
                    href="{{ route('project.service.database.backups', $parameters) }}">
                    <span class="menu-item-label">Back</span>
                </a>
                <a @class(['sub-menu-item', 'menu-item-active' => $section === 'general']) {{ wireNavigate() }}
                    href="{{ route('project.service.database.backup.show', $backupParameters) }}">
                    <span class="menu-item-label">General</span>
                </a>
                <a @class(['sub-menu-item', 'menu-item-active' => $section === 's3']) {{ wireNavigate() }}
                    href="{{ route('project.service.database.backup.s3', $backupParameters) }}">
                    <span class="menu-item-label">S3</span>
                </a>
                <a @class(['sub-menu-item', 'menu-item-active' => $section === 'retention']) {{ wireNavigate() }}
                    href="{{ route('project.service.database.backup.retention', $backupParameters) }}">
                    <span class="menu-item-label">Retention</span>
                </a>
                <a @class(['sub-menu-item', 'menu-item-active' => $section === 'executions']) {{ wireNavigate() }}
                    href="{{ route('project.service.database.backup.executions', $backupParameters) }}">
                    <span class="menu-item-label">Executions</span>
                </a>
                <a @class(['sub-menu-item', 'menu-item-active' => $section === 'danger']) {{ wireNavigate() }}
                    href="{{ route('project.service.database.backup.danger', $backupParameters) }}">
                    <span class="menu-item-label">Danger Zone</span>
                </a>
            </div>
        @else
            <x-service-database.sidebar :parameters="$parameters" :serviceDatabase="$serviceDatabase"
                :isImportSupported="$isImportSupported" />
        @endif

        <div class="w-full">
            <x-slot:title>
                {{ data_get_str($service, 'name')->limit(10) }} >
                {{ data_get_str($serviceDatabase, 'name')->limit(10) }} > Backups | Coolify
            </x-slot>

            @if ($backup)
                @if ($section === 'executions')
                    <livewire:project.database.backup-executions :backup="$backup" :database="$serviceDatabase" />
                @else
                    <livewire:project.database.backup-edit :backup="$backup" :available-s3-storages="$s3s"
                        :status="data_get($serviceDatabase, 'status')" :section="$section"
                        wire:key="service-database-backup-{{ $backup->uuid }}-{{ $section }}" />
                @endif
            @else
                <div class="flex gap-2">
                    <h2 class="pb-4">Scheduled Backups</h2>
                    @if (filled($serviceDatabase->custom_type) || !$serviceDatabase->is_migrated)
                        @can('update', $serviceDatabase)
                            <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" />
                            </x-modal-input>
                        @endcan
                    @endif
                </div>
                <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
            @endif
        </div>
    </div>
</div>
