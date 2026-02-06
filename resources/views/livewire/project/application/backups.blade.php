<div class="flex flex-col h-full gap-8 sm:flex-row">
    <div class="sub-menu-wrapper">
        <div class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
            Databases
        </div>
        @forelse ($databases as $db)
            <button type="button"
                class="sub-menu-item w-full text-left {{ $selectedDatabase?->uuid === $db->uuid ? 'menu-item-active' : '' }}"
                wire:click="selectDatabase('{{ $db->uuid }}')">
                <span class="menu-item-label">{{ $db->name }}</span>
            </button>
        @empty
            <div class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">
                No database services detected in this docker-compose.
            </div>
        @endforelse
    </div>

    <div class="w-full">
        @if ($selectedDatabase)
            <div class="flex items-center gap-2">
                <h2 class="pb-4">Scheduled Backups</h2>
                @if ($selectedDatabase->isBackupSolutionAvailable() || $selectedDatabase->is_migrated)
                    @if (filled($selectedDatabase->custom_type) || ! $selectedDatabase->is_migrated)
                        @can('update', $selectedDatabase)
                            <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                                <livewire:project.database.create-scheduled-backup :database="$selectedDatabase" />
                            </x-modal-input>
                        @endcan
                    @endif
                @else
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        (Backups not supported for this database type.)
                    </span>
                @endif
            </div>

            <livewire:project.database.scheduled-backups :database="$selectedDatabase" />
        @else
            <div class="text-sm text-gray-600 dark:text-gray-400">
                No database services detected. Ensure your docker-compose has a supported database service image (e.g.
                Postgres/MySQL/MariaDB/MongoDB) and redeploy.
            </div>
        @endif
    </div>
</div>

