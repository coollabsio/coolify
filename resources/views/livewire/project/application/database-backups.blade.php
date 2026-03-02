<div>
    <livewire:project.application.heading :application="$application" />
    <div class="w-full">
        <x-slot:title>
            {{ data_get_str($application, 'name')->limit(10) }} >
            {{ data_get_str($serviceDatabase, 'name')->limit(10) }} > Backups | Coolify
        </x-slot>
        <div class="flex gap-2">
            <h2 class="pb-4">Scheduled Backups for {{ $serviceDatabase->name }}</h2>
            @can('update', $application)
                <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                    <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" />
                </x-modal-input>
            @endcan
        </div>
        <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
    </div>
</div>
