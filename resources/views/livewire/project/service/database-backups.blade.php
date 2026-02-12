<div>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-service-database.sidebar :parameters="$parameters" :serviceDatabase="$serviceDatabase" :isImportSupported="$isImportSupported" />
        <div class="w-full">
            <x-slot:title>
                {{ data_get_str($service, 'name')->limit(10) }} >
                {{ data_get_str($serviceDatabase, 'name')->limit(10) }} > Backups | Coolify
            </x-slot>
            <div class="form-section-title mb-6">
                <h2>Scheduled Backups</h2>
                <div class="flex items-center gap-2">
                    @if (filled($serviceDatabase->custom_type) || !$serviceDatabase->is_migrated)
                        @can('update', $serviceDatabase)
                            <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" />
                            </x-modal-input>
                        @endcan
                    @endif
                </div>
            </div>
            <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
        </div>
    </div>
</div>
