<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <h1>Backups</h1>
    <livewire:project.shared.configuration-checker :resource="$database" />
    <livewire:project.database.heading :database="$database" />

    @if ($database->type() === 'standalone-postgresql')
        <div class="pb-8">
            <livewire:project.database.postgresql.pgbackrest :database="$database" />
        </div>
    @endif

    <div>
        <div class="flex gap-2">
            <h2 class="pb-4">Scheduled Backups</h2>
            @can('update', $database)
                <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                    <livewire:project.database.create-scheduled-backup :database="$database" />
                </x-modal-input>
            @endcan
        </div>
        <livewire:project.database.scheduled-backups :database="$database" />
    </div>
</div>
