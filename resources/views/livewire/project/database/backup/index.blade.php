<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>

    <livewire:project.database.heading :database="$database" />

    <div class="mt-8 w-full max-w-[1180px] lg:mt-3">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Scheduled backups</h2>
                    <p>Automate database backups and track the latest execution for each schedule.</p>
                </div>
                @can('update', $database)
                    <x-modal-input buttonTitle="+ Add" title="New Scheduled Backup" isHighlightedButton>
                        <livewire:project.database.create-scheduled-backup :database="$database" />
                    </x-modal-input>
                @endcan
            </div>
            <div class="application-settings-section-body p-0!">
                <livewire:project.database.scheduled-backups :database="$database" />
            </div>
        </section>
    </div>
</div>
