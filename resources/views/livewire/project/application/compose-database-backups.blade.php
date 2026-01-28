<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} >
        {{ data_get_str($composeDatabase, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <h1>Configuration</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.application.database-backups', $parameters) }}">
                <span class="menu-item-label">← Back to Databases</span>
            </a>
        </div>
        <div class="w-full">
            <div class="flex gap-2">
                <h2 class="pb-4">{{ $composeDatabase->name }} — Scheduled Backups</h2>
                @can('update', $application)
                    <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup">
                        <livewire:project.database.create-scheduled-backup :database="$composeDatabase" />
                    </x-modal-input>
                @endcan
            </div>
            <livewire:project.database.scheduled-backups :database="$composeDatabase" />

            @if ($isImportSupported)
                <div class="pt-8">
                    <h3 class="pb-4">Import Database</h3>
                    <livewire:project.database.import :database="$composeDatabase" />
                </div>
            @endif
        </div>
    </div>
</div>
