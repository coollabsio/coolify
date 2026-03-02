<div>
    <livewire:project.application.heading :application="$application" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class="sub-menu-item"
                {{ wireNavigate() }}
                href="{{ route('project.application.configuration', [
                    'project_uuid' => $parameters['project_uuid'],
                    'environment_uuid' => $parameters['environment_uuid'],
                    'application_uuid' => $parameters['application_uuid'],
                ]) }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="sub-menu-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                <span class="menu-item-label">Back</span>
            </a>
            <a class="sub-menu-item" wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.application.compose.database.backups', $parameters) }}"><span class="menu-item-label">Backups</span></a>
        </div>
        <div class="w-full">
            <x-slot:title>
                {{ data_get_str($application, 'name')->limit(10) }} >
                {{ data_get_str($serviceDatabase, 'name')->limit(10) }} > Backups | Coolify
            </x-slot>
            <div class="flex gap-2">
                <h2 class="pb-4">Scheduled Backups</h2>
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
