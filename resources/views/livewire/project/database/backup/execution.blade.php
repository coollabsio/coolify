<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Backup | Coolify
    </x-slot>

    <livewire:project.database.heading :database="$database" />

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <x-backup-sidebar context="database" :parameters="$parameters" :section="$section" />

            <div class="min-w-0">
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
