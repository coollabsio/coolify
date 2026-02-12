<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <h1>Backups</h1>
    <livewire:project.shared.configuration-checker :resource="$database" />
    <livewire:project.database.heading :database="$database" />
    <div>
        <div class="form-section-title mb-6">
            <h2>Scheduled Backups</h2>
            <div class="flex items-center gap-2">
                @can('update', $database)
                    <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                        <livewire:project.database.create-scheduled-backup :database="$database" />
                    </x-modal-input>
                @endcan
            </div>
        </div>
        <livewire:project.database.scheduled-backups :database="$database" />
    </div>
</div>
