<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Database Backups | Coolify
    </x-slot>
    @if ($serviceDatabase)
        <div class="flex flex-col gap-4">
            <h2>Database Backups - {{ $serviceDatabase->name }}</h2>
            <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <a href="{{ route('project.application.configuration', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'application_uuid' => $parameters['application_uuid']]) }}"
                        class="button">← Back to Configuration</a>
                </div>
            </div>
            <div class="flex flex-col gap-4">
                <div>
                    <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" />
                </div>
                <div>
                    <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
                </div>
            </div>
        </div>
    @endif
</div>
