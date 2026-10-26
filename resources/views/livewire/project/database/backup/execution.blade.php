<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Backup | Coolify
    </x-slot>

    <livewire:project.database.heading :database="$database" />

    @can('manageBackups', $database)
        @teleport('#resource-action-hud-slot')
            <div class="order-first mr-1 hidden items-center gap-1 xl:flex">
                @php($databaseRunning = str($database->status)->startsWith('running'))
                @if ($backup->enabled)
                    <x-forms.button type="button" wire:click="toggleEnabled">Disable backup</x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleEnabled" isHighlighted>Enable backup</x-forms.button>
                @endif
                <x-forms.button type="button" wire:click="backupNow" :disabled="! $databaseRunning"
                    :tooltip="! $databaseRunning ? 'The database must be running to start a backup.' : null">
                    Back up now
                </x-forms.button>
            </div>
        @endteleport
    @endcan

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            {{-- The database menu, one Back away from the backup menu in the main sidebar. --}}
            <x-database.configuration-sidebar :database="$database" current-route="project.database.backup.index" nav-only />
            <x-backup-sidebar context="database" :parameters="$parameters" :section="$section" />

            <div class="min-w-0">
                @if ($section === 'executions')
                    <livewire:project.database.backup-executions :backup="$backup" />
                @else
                    <livewire:project.database.backup-edit :backup="$backup" :available-s3-storages="$s3s"
                        :status="data_get($database, 'status')" :section="$section"
                        :actions-in-header="true" wire:key="database-backup-{{ $backup->uuid }}-{{ $section }}" />
                @endif
            </div>
        </div>
    </section>
</div>
