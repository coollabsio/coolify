<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Storage Backups | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="request()->query()"
        wire:key="service-heading-volume-backup-show" />

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <x-service.configuration-sidebar :service="$service"
                current-route="project.service.volume-backups.index" />

            <div class="flex min-w-0 flex-col gap-6">
                <div class="flex min-w-0 flex-col gap-4">
                    <div>
                        <a class="inline-flex items-center gap-1.5 text-xs text-neutral-500 hover:text-neutral-900 dark:text-fg-dim dark:hover:text-fg"
                            {{ wireNavigate() }}
                            href="{{ route('project.service.volume-backups.index', collect($parameters)->except('backup_uuid')->all()) }}">
                            <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                            Back to backups
                        </a>
                        <h1 class="mt-2 text-xl font-semibold text-neutral-950 dark:text-fg">
                            {{ $backup->targetName() }} backup
                        </h1>
                        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                            {{ $backup->frequency }} schedule
                        </p>
                    </div>

                    <x-backup-tabs context="service-volume" :parameters="$parameters" :section="$section" />
                </div>

                <livewire:project.shared.storages.volume-backups :storage="$backup->backupable"
                    :resource="$service" :section="$section"
                    wire:key="service-volume-backup-{{ $backup->uuid }}-{{ $section }}" />
            </div>
        </div>
    </section>
</div>
