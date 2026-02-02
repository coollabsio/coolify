<div>
    <h2>Database Backups</h2>
    <p class="text-sm text-neutral-400 mb-4">
        Manage scheduled backups for databases detected in your Docker Compose file.
    </p>

    @if ($serviceDatabases->count() > 1)
        <div class="mb-6">
            <h3 class="text-sm font-medium mb-2">Select Database</h3>
            <div class="flex flex-wrap gap-2">
                @foreach ($serviceDatabases as $db)
                    <button 
                        wire:click="selectDatabase('{{ $db->uuid }}')"
                        class="px-3 py-2 rounded-md text-sm font-medium transition-colors
                            {{ $selectedDatabase && $selectedDatabase->uuid === $db->uuid 
                                ? 'bg-coollabs text-white' 
                                : 'bg-neutral-700 text-neutral-200 hover:bg-neutral-600' }}"
                    >
                        {{ $db->name }}
                        <span class="text-xs opacity-70 ml-1">({{ str($db->databaseType())->after('standalone-') }})</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if ($selectedDatabase)
        <div class="border border-neutral-700 rounded-lg p-4">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold">{{ $selectedDatabase->name }}</h3>
                    <p class="text-sm text-neutral-400">
                        <span class="font-medium">Image:</span> {{ $selectedDatabase->image }}
                    </p>
                    <p class="text-sm text-neutral-400">
                        <span class="font-medium">Type:</span> {{ str($selectedDatabase->databaseType())->after('standalone-')->ucfirst() }}
                    </p>
                </div>
                @can('update', $application)
                    <x-modal-input buttonTitle="+ Add Backup" title="New Scheduled Backup">
                        <livewire:project.database.create-scheduled-backup 
                            :database="$selectedDatabase" 
                            :key="'create-backup-'.$selectedDatabase->uuid" 
                        />
                    </x-modal-input>
                @endcan
            </div>

            <livewire:project.database.scheduled-backups 
                :database="$selectedDatabase" 
                :key="'backups-'.$selectedDatabase->uuid" 
            />
        </div>
    @else
        <div class="text-center py-8 text-neutral-400">
            <p>No databases with backup support were detected in your Docker Compose file.</p>
            <p class="text-sm mt-2">
                Supported databases: PostgreSQL, MySQL, MariaDB, MongoDB
            </p>
        </div>
    @endif

    <div class="mt-6 p-4 bg-neutral-800/50 rounded-lg">
        <h4 class="text-sm font-medium mb-2">💡 Tip</h4>
        <p class="text-sm text-neutral-400">
            You can manually specify that a service is a database by adding the label 
            <code class="bg-neutral-700 px-1 py-0.5 rounded">coolify.service.subType=database</code> 
            to the service in your docker-compose.yml file.
        </p>
    </div>
</div>
