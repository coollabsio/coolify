<div>
    <h2 class="pb-4">Database Backups</h2>
    <p class="pb-4 text-sm">Databases detected in your Docker Compose file. You can configure scheduled backups for each database.</p>

    @if ($databases->count() === 0)
        <div class="text-neutral-400">
            No supported databases detected in your Docker Compose file.
            <br>
            Supported databases: PostgreSQL, MySQL, MariaDB, MongoDB.
        </div>
    @else
        <div class="flex flex-col gap-4">
            @foreach ($databases as $database)
                <div class="box p-4">
                    <div class="flex items-center justify-between pb-2">
                        <div>
                            <h3 class="font-bold">{{ $database->name }}</h3>
                            <p class="text-sm text-neutral-400">{{ $database->image }} ({{ str($database->databaseType())->after('standalone-') }})</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="badge {{ $database->isRunning() ? 'badge-success' : 'badge-warning' }}">
                                {{ $database->isRunning() ? 'Running' : $database->status }}
                            </span>
                        </div>
                    </div>
                    <div class="pt-2 border-t border-coolgray-300">
                        <div class="flex gap-2 pb-4">
                            <h4>Scheduled Backups</h4>
                            @can('update', $application)
                                <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                    <livewire:project.database.create-scheduled-backup :database="$database" :wire:key="'create-backup-' . $database->id" />
                                </x-modal-input>
                            @endcan
                        </div>
                        <livewire:project.database.scheduled-backups :database="$database" :wire:key="'backups-' . $database->id" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
