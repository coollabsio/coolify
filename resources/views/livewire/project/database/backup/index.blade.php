<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <h1>{{ __('backup.title') }}</h1>
    <livewire:project.shared.configuration-checker :resource="$database" />
    <livewire:project.database.heading :database="$database" />
    <div>
        <div class="flex gap-2">
            <h2 class="pb-4">{{ __('backup.scheduled_backups') }}</h2>
            @can('update', $database)
                <x-modal-input buttonTitle="{{ __('backup.add_button') }}" title="{{ __('backup.new_scheduled_backup') }}">
                    <livewire:project.database.create-scheduled-backup :database="$database" />
                </x-modal-input>
            @endcan
        </div>
        <livewire:project.database.scheduled-backups :database="$database" />
    </div>
</div>
