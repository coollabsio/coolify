<div class="flex min-w-0 flex-col gap-6" x-data="{
    search: '',
    backups: @js($database->scheduledBackups->map(fn ($backup) => [
        'name' => strtolower($database->name),
        'frequency' => strtolower($backup->frequency),
        's3_storage' => strtolower(($backup->s3Storages->isNotEmpty() ? $backup->s3Storages : collect([$backup->s3])->filter())->pluck('name')->join(', ')),
    ])->values()),
    hasMatches() {
        const query = this.search.toLowerCase();

        return this.backups.some((backup) =>
            backup.name.includes(query)
            || backup.frequency.includes(query)
            || backup.s3_storage.includes(query)
        );
    },
}">
    @if ($selectedBackup)
        <x-modal-input :title="'Edit backup schedule'"
            :subtitle="$database->name.' · '.$selectedBackup->frequency.' schedule'"
            wireOpen="scheduleModalOpen" :wireIgnore="false" isLarge fixedHeight>
            <x-slot:content><span></span></x-slot:content>

            <div x-data="{ activeSection: 'general' }" class="modal-flat-sections flex min-w-0 flex-col gap-6">
                <x-backup-tabs context="service-schedule" :parameters="$parameters" section="general" />

                @foreach (['general', 'danger'] as $modalSection)
                    <div x-show="activeSection === '{{ $modalSection }}'" x-cloak>
                        <livewire:project.database.backup-edit :backup="$selectedBackup"
                            :status="data_get($database, 'status')" :section="$modalSection"
                            wire:key="database-backup-modal-{{ $selectedBackup->uuid }}-{{ $modalSection }}" />
                    </div>
                @endforeach
            </div>
        </x-modal-input>
    @endif

    @if ($database->is_migrated && blank($database->custom_type))
        <form wire:submit="setCustomType" class="grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <div>
                <x-forms.listbox id="custom_type" label="Database type" :options="[
                    ['value' => 'mysql', 'label' => 'MySQL'],
                    ['value' => 'mariadb', 'label' => 'MariaDB'],
                    ['value' => 'postgresql', 'label' => 'PostgreSQL'],
                    ['value' => 'mongodb', 'label' => 'MongoDB'],
                ]" />
                <p class="mt-2 text-xs text-neutral-500 dark:text-fg-dim">
                    Select the database engine before enabling automated backups.
                </p>
            </div>
            <x-forms.button type="submit">Set database type</x-forms.button>
        </form>
    @else
        @if ($database->scheduledBackups->isNotEmpty())
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input type="search" x-model="search" aria-label="Search backups"
                        class="input h-8! w-full pl-8! text-[13px]!" placeholder="Search backups" />
                </div>
            </div>
        @endif

        <x-application.settings-section title="Schedules"
            helper="Backup schedules of this database." :flush="$database->scheduledBackups->isNotEmpty()">
            @if ($database->scheduledBackups->isEmpty())
                <x-empty size="sm" title="No scheduled backups"
                    description="Create a schedule to start protecting this database."
                    icon-name="storages" />
            @else
                <div x-cloak x-show="search !== '' && !hasMatches()">
                    <x-empty size="sm" title="No matching backup schedules"
                        description="Try another database name, frequency, or storage name." />
                </div>
                <div class="data-table w-full overflow-x-auto" x-show="search === '' || hasMatches()">
                    <div class="flex min-w-[48rem] flex-col">
                        <div class="data-table-header database-backup-table-grid">
                            <span>Schedule</span>
                            <span>Status</span>
                            <span>S3</span>
                            <span>Last run</span>
                            <span>Executions</span>
                            <span class="text-right">Actions</span>
                        </div>
                        @foreach ($database->scheduledBackups as $backup)
                            @php
                                $latestExecution = $backup->latest_log;
                                $latestStatus = $latestExecution?->status;
                                $s3Storages = $backup->s3Storages->isNotEmpty() ? $backup->s3Storages : collect([$backup->s3])->filter();
                                $s3StorageNames = $s3Storages->pluck('name')->join(', ');
                                $s3Tooltip = $backup->save_s3 && $s3Storages->isNotEmpty()
                                    ? 'S3 storage: '.$s3Storages->map(fn ($s3) => $s3->name.' ('.($s3->isS3() ? 'bucket: '.$s3->bucket : $s3->type->label()).')')->join(', ')
                                    : null;
                                [$statusLabel, $statusType] = match ($latestStatus) {
                                    'running' => ['In progress', 'warning'],
                                    'success' => ['Success', 'success'],
                                    'failed' => ['Failed', 'error'],
                                    default => [$backup->enabled ? 'Waiting' : 'Disabled', 'neutral'],
                                };
                            @endphp
                            <div wire:key="database-backup-schedule-{{ $backup->uuid }}"
                                x-show="search === ''
                                || @js(strtolower($database->name)).includes(search.toLowerCase())
                                || @js(strtolower($backup->frequency)).includes(search.toLowerCase())
                                || @js(strtolower($s3StorageNames)).includes(search.toLowerCase())"
                                wire:click="openSchedule('{{ $backup->uuid }}')"
                                wire:keydown.enter="openSchedule('{{ $backup->uuid }}')" role="button" tabindex="0"
                                class="data-table-row database-backup-table-grid cursor-pointer text-left text-[13px] text-neutral-700 dark:text-fg-dim">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="truncate font-medium text-neutral-950 dark:text-fg">{{ $backup->frequency }}</span>
                                    @if (! $backup->enabled && $latestStatus)
                                        <x-status-badge status="Disabled" />
                                    @endif
                                </span>
                                <span class="flex items-center gap-2">
                                    <x-status-badge :status="$statusLabel" :type="$statusType" />
                                </span>
                                <span class="min-w-0">
                                    <x-backup-s3-storage :enabled="$backup->save_s3" :names="$s3Storages->pluck('name')" :tooltip="$s3Tooltip" />
                                </span>
                                <span>{{ $latestExecution?->finished_at?->diffForHumans() ?? ($latestStatus === 'running' ? 'Running now' : 'Never') }}</span>
                                <span>{{ $backup->executions_count ?? $backup->executions()->count() }}</span>
                                <span class="flex justify-end" x-on:click.stop x-on:keydown.enter.stop>
                                    <x-table.row-actions label="Backup schedule actions">
                                        @can('manageBackups', $database)
                                            <x-table.row-action
                                                :disabled="! str($database->status)->startsWith('running')"
                                                :title="! str($database->status)->startsWith('running') ? 'The database must be running to start a backup.' : null"
                                                wire:click="backupNow('{{ $backup->uuid }}')">Back up now</x-table.row-action>
                                        @endcan
                                        <x-table.row-action
                                            wire:click="openSchedule('{{ $backup->uuid }}')">Edit</x-table.row-action>
                                    </x-table.row-actions>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-application.settings-section>
    @endif
</div>
