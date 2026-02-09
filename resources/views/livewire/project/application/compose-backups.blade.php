<div>
    <h2 class="pb-4">Database Backups</h2>
    @if ($composeDatabases->isEmpty())
        <div class="text-neutral-500">
            No database services detected in this Docker Compose file. Database services are automatically detected based on their image (PostgreSQL, MySQL, MariaDB, MongoDB).
        </div>
    @else
        <div class="flex flex-col gap-4 sm:flex-row">
            <div class="flex flex-col gap-2 min-w-[200px]">
                <h3 class="pb-2">Databases</h3>
                @foreach ($composeDatabases as $db)
                    <button wire:click="selectDatabase({{ $db->id }})"
                        @class([
                            'text-left px-4 py-2 rounded text-sm',
                            'dark:bg-coolgray-200 bg-neutral-200 font-bold' => $selectedDatabase && $selectedDatabase->id === $db->id,
                            'dark:bg-coolgray-100 bg-white hover:dark:bg-coolgray-200 hover:bg-neutral-100' => !$selectedDatabase || $selectedDatabase->id !== $db->id,
                        ])>
                        {{ $db->name }}
                        <span class="text-xs opacity-60">({{ $db->image }})</span>
                    </button>
                @endforeach
            </div>
            <div class="flex-1">
                @if ($selectedDatabase)
                    <div class="flex gap-2 items-center pb-4">
                        <h3>Scheduled Backups for {{ $selectedDatabase->name }}</h3>
                        @can('update', $application)
                            <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                <livewire:project.database.create-scheduled-backup :database="$selectedDatabase"
                                    wire:key="create-backup-{{ $selectedDatabase->id }}" />
                            </x-modal-input>
                        @endcan
                    </div>
                    <livewire:project.database.scheduled-backups :database="$selectedDatabase"
                        wire:key="backups-{{ $selectedDatabase->id }}" />
                @else
                    <div class="text-neutral-500">Select a database to manage its backups.</div>
                @endif
            </div>
        </div>
    @endif
</div>
