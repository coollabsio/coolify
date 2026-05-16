<div>
    <div class="flex items-center gap-2">
        <h2>Backups</h2>
    </div>
    <div class="pb-4 border-b border-coolgray-700">Database backups for your application.</div>

    <div class="flex flex-col gap-8 pt-8">
        @forelse ($databases as $database)
            <div class="p-4 border border-coolgray-700 rounded-lg bg-coolgray-900">
                <div class="flex items-center justify-between pb-4 border-b border-coolgray-800 mb-4">
                    <div class="flex items-center gap-2">
                        <h3 class="text-xl font-bold text-white">{{ $database->name }}</h3>
                        <span class="px-2 py-1 text-xs rounded-full {{ str(data_get($database, 'status', 'unknown'))->contains('running') ? 'bg-success/20 text-success' : 'bg-coolgray-700 text-coolgray-400' }}">{{ data_get($database, 'status', 'unknown') }}</span>
                    </div>
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex items-center gap-2">
                        <h4 class="text-lg font-semibold text-white">Scheduled Backups</h4>
                        @can('update', $database)
                            <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup" canGate="update" :canResource="$database">
                                <livewire:project.database.create-scheduled-backup :database="$database" :key="'create-scheduled-backup-'.$database->id" />
                            </x-modal-input>
                        @endcan
                    </div>
                    <livewire:project.database.scheduled-backups :database="$database" :key="$database->id" />
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center p-12 text-center bg-coolgray-900 border border-coolgray-800 rounded-xl">
                <svg class="w-16 h-16 mb-4 text-coolgray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.58 4 8 4s8-1.79 8-4M4 7c0-2.21 3.58-4 8-4s8 1.79 8 4m0 5c0 2.21-3.58 4-8 4s-8-1.79-8-4" />
                </svg>
                <div class="text-xl font-semibold text-coolgray-400">No Databases Detected</div>
                <div class="max-w-xs mt-2 text-coolgray-500">
                    We couldn't find any database services in your Docker Compose file. Backups are only available for detected database images.
                </div>
            </div>
        @endforelse
    </div>
</div>
