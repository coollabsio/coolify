<div>
    <h2>Database Backups</h2>
    <div class="pb-4 text-sm text-gray-400">
        Manage scheduled backups for databases detected in your Docker Compose file.
    </div>

    @if ($databases->isEmpty())
        <div class="text-gray-500">
            No databases detected in your Docker Compose file.
        </div>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($databases as $database)
                <div class="p-4 border rounded-lg border-coolgray-200 bg-coolgray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold">{{ $database->name }}</h3>
                            <div class="text-sm text-gray-400">
                                Image: {{ $database->image }}
                            </div>
                            <div class="text-sm text-gray-400">
                                Type: {{ $database->databaseType() }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($database->isBackupSolutionAvailable())
                                <span class="px-2 py-1 text-xs text-green-400 bg-green-900 rounded">
                                    Backup Available
                                </span>
                            @else
                                <span class="px-2 py-1 text-xs text-yellow-400 bg-yellow-900 rounded">
                                    No Backup Support
                                </span>
                            @endif
                        </div>
                    </div>

                    @if ($database->isBackupSolutionAvailable())
                        <div class="mt-4">
                            <div class="flex items-center gap-2 mb-2">
                                <h4 class="font-medium">Scheduled Backups</h4>
                                @can('update', $database)
                                    <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                        <livewire:project.database.create-scheduled-backup :database="$database" :key="'create-backup-'.$database->id" />
                                    </x-modal-input>
                                @endcan
                            </div>
                            <livewire:project.database.scheduled-backups :database="$database" :key="'backups-'.$database->id" />
                        </div>
                    @else
                        <div class="mt-4 text-sm text-gray-500">
                            Automatic backups are not available for this database type ({{ $database->databaseType() }}).
                            Supported types: PostgreSQL, MySQL, MariaDB, MongoDB.
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
