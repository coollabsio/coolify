<div>
    <h2 class="pb-4">Database Backups</h2>
    @if ($serviceDatabases->isEmpty())
        <div class="text-neutral-400">
            No database services detected in this Docker Compose file.
            <br>
            Databases are automatically detected when deploying. If you have database services (PostgreSQL, MySQL, MariaDB, MongoDB), redeploy to detect them.
        </div>
    @else
        <div class="flex flex-col gap-4">
            <div class="text-sm text-neutral-400">
                Select a database service to manage its backup schedule.
            </div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($serviceDatabases as $db)
                    <button wire:click="selectDatabase({{ $db->id }})"
                        class="flex flex-col gap-1 p-4 border rounded cursor-pointer border-coolgray-400 {{ $selectedDatabase?->id === $db->id ? 'bg-coolgray-200 border-warning' : 'hover:bg-coolgray-100' }}">
                        <div class="font-bold">{{ $db->name }}</div>
                        <div class="text-xs text-neutral-400">{{ $db->image }}</div>
                        <div class="text-xs">
                            {{ $db->scheduledBackups->count() }} scheduled backup(s)
                        </div>
                    </button>
                @endforeach
            </div>

            @if ($selectedDatabase)
                <div class="flex flex-col gap-4 pt-4 mt-4 border-t border-coolgray-400">
                    <div class="flex items-center gap-2">
                        <h3>{{ $selectedDatabase->name }} — Scheduled Backups</h3>
                        @can('update', $selectedDatabase)
                            <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                <livewire:project.database.create-scheduled-backup :database="$selectedDatabase" :key="'create-backup-'.$selectedDatabase->id" />
                            </x-modal-input>
                        @endcan
                    </div>
                    <livewire:project.database.scheduled-backups :database="$selectedDatabase" :key="'backups-'.$selectedDatabase->id" />
                </div>
            @endif
        </div>
    @endif
</div>
