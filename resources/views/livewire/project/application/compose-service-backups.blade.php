<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} >
        {{ data_get_str($serviceDatabase, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <livewire:project.application.heading :application="$application" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class="sub-menu-item"
                {{ wireNavigate() }}
                href="{{ route('project.application.configuration', $parameters) }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="sub-menu-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                <span class="menu-item-label">Back to Application</span>
            </a>
            <div class="px-4 py-2 text-xs font-semibold uppercase text-neutral-500 dark:text-neutral-400">
                {{ $serviceDatabase->name }}
            </div>
            <a class="sub-menu-item menu-item-active">
                <span class="menu-item-label">Backups</span>
            </a>
        </div>
        <div class="w-full">
            <div class="flex gap-2">
                <h2 class="pb-4">Scheduled Backups</h2>
                @can('update', $application)
                    <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                        <livewire:project.database.create-scheduled-backup :database="$serviceDatabase" />
                    </x-modal-input>
                @endcan
            </div>
            <div class="pb-4">
                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                    Backing up the <strong>{{ $serviceDatabase->name }}</strong> database service
                    from the <strong>{{ $application->name }}</strong> Docker Compose deployment.
                    <br>
                    <span class="text-warning">Note:</span> Backups will pause automatically if a redeployment is in progress.
                </p>
            </div>
            <livewire:project.database.scheduled-backups :database="$serviceDatabase" />
        </div>
    </div>
</div>
