<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Backup | Coolify
    </x-slot>

    <livewire:project.database.heading :database="$database" />

    <section class="application-settings-workspace mt-8 w-full max-w-[1180px] xl:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
            <x-backup-sidebar context="database" :parameters="$parameters" :section="$section" />

            <div class="min-w-0 xl:mt-3">
                @if ($section === 'executions')
                    <livewire:project.database.backup-executions :backup="$backup" />
                @else
                    <livewire:project.database.backup-edit :backup="$backup" :available-s3-storages="$s3s"
                        :status="data_get($database, 'status')" :section="$section"
                        wire:key="database-backup-{{ $backup->uuid }}-{{ $section }}" />
                @endif
            </div>
        </div>
    </section>
</div>
