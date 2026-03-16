<div>
    <h2 class="pb-4">Database Backups</h2>
    <p class="pb-4 text-sm text-gray-600 dark:text-gray-400">
        Manage automated backups for database services detected in your Docker Compose file.
    </p>
    @if ($companionDatabases->count() === 0)
        <div class="text-gray-500">No database services detected in your Docker Compose file. Deploy your application first to detect database services.</div>
    @else
        <div class="flex flex-col gap-6">
            @foreach ($companionDatabases as $db)
                <div class="p-4 border rounded-lg bg-white dark:bg-coolgray-100 border-gray-200 dark:border-coolgray-300">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="font-semibold text-lg">{{ $db->name }}</h3>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $db->image }} &middot; {{ str($db->databaseType())->after('standalone-')->title() }}</span>
                        </div>
                        @if ($db->isBackupSolutionAvailable())
                            @can('update', $db)
                                <x-modal-input buttonTitle="+ Add Backup" title="New Scheduled Backup for {{ $db->name }}">
                                    <livewire:project.database.create-scheduled-backup :database="$db" wire:key="create-backup-{{ $db->id }}" />
                                </x-modal-input>
                            @endcan
                        @else
                            <span class="text-sm text-gray-400">Backups not supported for this database type</span>
                        @endif
                    </div>
                    @if ($db->isBackupSolutionAvailable())
                        <livewire:project.database.scheduled-backups :database="$db" :type="'service-database'" wire:key="backups-{{ $db->id }}" />
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
