<div>
    <h2 class="pb-4">Docker Compose Database Backups</h2>

    @if ($composeDatabases->isEmpty())
        <div class="text-neutral-400">
            No database services detected in your Docker Compose file.
            <br>
            Database images (PostgreSQL, MySQL, MariaDB, MongoDB, etc.) are automatically detected when deploying.
        </div>
    @else
        <div class="flex flex-col gap-4">
            {{-- Database selector --}}
            <div class="flex flex-wrap gap-2">
                @foreach ($composeDatabases as $db)
                    <button
                        wire:click="selectDatabase('{{ $db->uuid }}')"
                        class="px-4 py-2 rounded text-sm font-medium transition-colors
                            {{ $selectedDatabaseUuid === $db->uuid
                                ? 'bg-coollabs text-white'
                                : 'bg-coolgray-200 text-neutral-300 hover:bg-coolgray-300' }}"
                    >
                        {{ $db->name }} <span class="text-xs opacity-75">({{ str($db->image)->before(':') }})</span>
                    </button>
                @endforeach
            </div>

            {{-- Backup management for selected database --}}
            @if ($selectedDatabase)
                @if ($selectedDatabase->isBackupSolutionAvailable())
                    <div class="w-full">
                        <div class="flex gap-2 items-center">
                            <h3 class="pb-2">Scheduled Backups for {{ $selectedDatabase->name }}</h3>
                            @can('update', $selectedDatabase)
                                <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                    <livewire:project.database.create-scheduled-backup :database="$selectedDatabase" :key="'create-backup-'.$selectedDatabase->uuid" />
                                </x-modal-input>
                            @endcan
                        </div>
                        <livewire:project.database.scheduled-backups :database="$selectedDatabase" :key="'backups-'.$selectedDatabase->uuid" />
                    </div>
                @else
                    <div class="text-neutral-400">
                        Backups are not available for this database type ({{ $selectedDatabase->databaseType() }}).
                        <br>
                        Supported database types: PostgreSQL, MySQL, MariaDB, MongoDB.
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
