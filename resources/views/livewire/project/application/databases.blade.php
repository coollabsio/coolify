<div>
    <h2 class="pb-4">Detected Databases</h2>
    <div class="flex flex-col gap-2">
        @forelse ($databases as $database)
            <div class="flex flex-col gap-1 p-4 bg-white dark:bg-coolgray-100">
                <div class="flex items-center justify-between">
                    <div class="flex flex-col">
                        <span class="font-semibold">{{ $database->human_name ?: \Illuminate\Support\Str::headline($database->name) }}</span>
                        <span class="text-sm text-gray-500">{{ $database->image }}</span>
                    </div>
                    <div class="flex gap-2">
                        @if ($database->isBackupSolutionAvailable())
                            <a {{ wireNavigate() }}
                                href="{{ route('project.application.database.backups', [...$parameters, 'stack_service_uuid' => $database->uuid]) }}"
                                class="button">
                                Backups
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="text-sm text-gray-500">No databases detected in the compose file.</div>
        @endforelse
    </div>
</div>
