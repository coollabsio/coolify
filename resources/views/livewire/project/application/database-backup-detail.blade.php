<div>
    <a wire:navigate
        href="{{ route('project.application.database-backups', [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
        ]) }}"
        class="inline-flex items-center gap-2 pb-4 text-sm text-neutral-400 hover:text-white">
        ← Back to databases
    </a>

    <h2 class="pb-2">{{ $serviceDatabase->name }}</h2>
    <p class="pb-4 text-sm text-neutral-400">{{ $serviceDatabase->image }} — {{ str($serviceDatabase->databaseType())->replace('standalone-', '') }}</p>

    @if ($serviceDatabase->isBackupSolutionAvailable())
        <div class="flex flex-col gap-4">
            <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" />
            <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
        </div>
    @else
        <p>Backups are not supported for this database type.</p>
    @endif
</div>
