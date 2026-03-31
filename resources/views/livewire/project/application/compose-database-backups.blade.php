<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} >
        {{ data_get_str($serviceDatabase, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <h1>Backups</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />
    <div class="flex flex-col gap-2">
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
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Manage backups for the <span class="font-medium">{{ $serviceDatabase->name }}</span> database service inside this Docker Compose application.
        </p>
        <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
    </div>
</div>
