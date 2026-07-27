<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>

    <livewire:project.application.heading :application="$application" />

    <section class="application-settings-workspace mt-8 w-full max-w-[1180px] xl:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
            <x-backup-sidebar context="application" :parameters="$parameters" :section="$section" />

            <div class="min-w-0 xl:mt-3">
                <livewire:project.shared.storages.volume-backups :storage="$backup->backupable"
                    :resource="$application" :section="$section"
                    wire:key="volume-backup-{{ $backup->uuid }}-{{ $section }}" />
            </div>
        </div>
    </section>
</div>
