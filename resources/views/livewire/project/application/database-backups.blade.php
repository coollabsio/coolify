<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Database Backups | Coolify
    </x-slot>
    <h2 class="pb-4">Database Backups</h2>
    <p class="pb-4">Manage scheduled backups for databases detected in your Docker Compose deployment.</p>

    @if ($serviceDatabases->count() > 1)
        <div class="flex gap-2 pb-4">
            @foreach ($serviceDatabases as $db)
                <button wire:click="selectDatabase({{ $db->id }})"
                    class="px-4 py-2 rounded {{ $selectedDatabase && $selectedDatabase->id === $db->id ? 'bg-coollabs text-white' : 'bg-coolgray-200 dark:bg-coolgray-100' }}">
                    {{ $db->name }}
                </button>
            @endforeach
        </div>
    @endif

    @if ($selectedDatabase)
        @if ($selectedDatabase->isBackupSolutionAvailable())
            <div class="flex gap-2 pb-4">
                <h3>{{ $selectedDatabase->name }}</h3>
                @can('update', $application)
                    <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                        <livewire:project.database.create-scheduled-backup :database="$selectedDatabase"
                            :key="'create-backup-' . $selectedDatabase->id" />
                    </x-modal-input>
                @endcan
            </div>
            <livewire:project.database.scheduled-backups :database="$selectedDatabase"
                :key="'backups-' . $selectedDatabase->id" />
        @else
            <div class="pb-4">
                <p>Backup is not supported for this database type ({{ $selectedDatabase->databaseType() }}).</p>
            </div>
        @endif
    @else
        <p>No databases detected in this Docker Compose deployment.</p>
    @endif
</div>
