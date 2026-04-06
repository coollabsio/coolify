<div>
    <div class="flex gap-2">
        <h2 class="pb-4">Backups</h2>
    </div>
    <div class="flex flex-col gap-2">
        @forelse ($application->service_databases as $database)
            <div class="p-4 border rounded shadow-sm bg-base-100 border-base-300">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold">{{ $database->name }}</h3>
                        <p class="text-sm text-neutral-500">{{ $database->image }}</p>
                    </div>
                </div>
                <div class="pt-4">
                    <div class="flex gap-2">
                        <h4 class="pb-4">Scheduled Backups</h4>
                        @can('update', $application)
                            <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup for {{ $database->name }}">
                                <livewire:project.database.create-scheduled-backup :database="$database" />
                            </x-modal-input>
                        @endcan
                    </div>
                    <livewire:project.database.scheduled-backups :database="$database" />
                </div>
            </div>
        @empty
            <div class="p-4 border rounded shadow-sm bg-base-100 border-base-300">
                <p>No databases detected in this application. Automated database detection happens during deployment.</p>
            </div>
        @endforelse
    </div>
</div>
