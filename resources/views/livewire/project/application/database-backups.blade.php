<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} >
        {{ data_get_str($serviceDatabase, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <livewire:project.application.heading :application="$application" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.application.configuration', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Back to Configuration</span></a>
        </div>
        <div class="w-full">
            <div class="flex gap-2">
                <h2 class="pb-4">Scheduled Backups for {{ $serviceDatabase->name }}</h2>
                @can('update', $application)
                    <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                        <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" />
                    </x-modal-input>
                @endcan
            </div>
            <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
        </div>
    </div>
</div>
