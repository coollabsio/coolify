<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Storage Backups | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="request()->query()"
        wire:key="service-heading-volume-backup-show" />

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <x-backup-sidebar context="service-volume" :parameters="$parameters" :section="$section" />

            <div class="min-w-0">
                <livewire:project.shared.storages.volume-backups :storage="$backup->backupable"
                    :resource="$service" :section="$section"
                    wire:key="service-volume-backup-{{ $backup->uuid }}-{{ $section }}" />
            </div>
        </div>
    </section>
</div>
